<?php

namespace App\Services\Global\Calendly;

use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connecting the company's Calendly account over OAuth 2.0 (authorization
 * code grant), and disconnecting it.
 *
 * The state sent to Calendly is a random one-time value remembered for the
 * user who asked to connect; the callback - a browser arriving from Calendly,
 * with no API token - is only honoured with that value, once.
 */
class CalendlyOAuthService
{
    private const STATE_TTL_MINUTES = 15;

    private const STATE_CACHE_PREFIX = 'calendly:oauth-state:';

    public function __construct(
        private readonly CalendlyClient $client,
    ) {}

    public function authorizeUrl(User $user): string
    {
        $state = Str::random(64);
        Cache::put(self::STATE_CACHE_PREFIX.$state, $user->id, now()->addMinutes(self::STATE_TTL_MINUTES));

        return rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.calendly.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            // Calendly grants only what is asked for here (space-separated).
            'scope' => trim((string) config('services.calendly.scopes')),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function redirectUri(): string
    {
        return config('services.calendly.redirect_uri') ?: url('/api/v1/calendly/callback');
    }

    public function webhookUrl(): string
    {
        return config('services.calendly.webhook_url') ?: url('/api/v1/calendly/webhook');
    }

    /** The user the state was issued to - once; null for an unknown, expired or used state. */
    public function consumeState(?string $state): ?User
    {
        if (! $state) {
            return null;
        }

        $userId = Cache::pull(self::STATE_CACHE_PREFIX.$state);

        return $userId ? User::find($userId) : null;
    }

    /**
     * Swaps the code for tokens and stores the account as the company's
     * Calendly, replacing any account connected before.
     */
    public function connect(User $user, string $code): CalendlyConnection
    {
        $tokens = Http::asForm()->acceptJson()->timeout(20)
            ->post(rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => config('services.calendly.client_id'),
                'client_secret' => config('services.calendly.client_secret'),
            ]);

        if ($tokens->failed() || ! $tokens->json('access_token')) {
            Log::warning('[CALENDLY] Authorization code refused', ['status' => $tokens->status(), 'error' => $tokens->json('error')]);

            throw CalendlyException::authorizationFailed();
        }

        $me = Http::withToken($tokens->json('access_token'))->acceptJson()->timeout(20)
            ->get($this->client->url('users/me'));

        if ($me->failed()) {
            throw CalendlyException::fromResponse($me);
        }

        $account = $me->json('resource') ?? [];

        foreach (CalendlyConnection::where('calendly_user_uri', '!=', $account['uri'] ?? '')->get() as $previous) {
            $this->disconnect($previous);
        }

        $connection = DB::transaction(fn () => CalendlyConnection::updateOrCreate(
            ['calendly_user_uri' => $account['uri']],
            [
                'connected_by' => $user->id,
                'calendly_organization_uri' => $account['current_organization'] ?? $tokens->json('organization'),
                'name' => $account['name'] ?? null,
                'email' => $account['email'] ?? null,
                'scheduling_url' => $account['scheduling_url'] ?? null,
                'timezone' => $account['timezone'] ?? null,
                'access_token' => $tokens->json('access_token'),
                'refresh_token' => $tokens->json('refresh_token'),
                'token_expires_at' => now()->addSeconds((int) ($tokens->json('expires_in') ?: 7200)),
                'status' => CalendlyConnection::STATUS_ACTIVE,
            ]
        ));

        $connection->update(['webhook_subscription_uri' => $this->subscribeToWebhooks($connection)]);

        return $connection->fresh();
    }

    /** Stops the webhooks and revokes the token; the meetings already brought in stay. */
    public function disconnect(?CalendlyConnection $connection = null): void
    {
        $connection ??= CalendlyConnection::current();

        if (! $connection) {
            return;
        }

        if ($connection->webhook_subscription_uri && $connection->isActive()) {
            try {
                $this->client->delete($connection->webhook_subscription_uri);
            } catch (Throwable $e) {
                Log::warning('[CALENDLY] Webhook subscription not removed', ['error' => $e->getMessage()]);
            }
        }

        try {
            Http::asForm()->acceptJson()->timeout(20)
                ->post(rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/revoke', [
                    'token' => $connection->refresh_token,
                    'client_id' => config('services.calendly.client_id'),
                    'client_secret' => config('services.calendly.client_secret'),
                ]);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Token not revoked', ['error' => $e->getMessage()]);
        }

        $connection->delete();
    }

    /**
     * Subscribes the account's bookings and cancellations to our webhook URL;
     * reuses the subscription when Calendly already has one for it. A URL
     * Calendly cannot reach (a laptop) leaves the account connected without
     * webhooks - the hourly sync still brings meetings in.
     */
    private function subscribeToWebhooks(CalendlyConnection $connection): ?string
    {
        $url = $this->webhookUrl();

        try {
            return $this->client->post('webhook_subscriptions', array_filter([
                'url' => $url,
                'events' => CalendlySyncService::WEBHOOK_EVENTS,
                'organization' => $connection->calendly_organization_uri,
                'user' => $connection->calendly_user_uri,
                'scope' => 'user',
                'signing_key' => config('services.calendly.webhook_signing_key'),
            ]))['resource']['uri'] ?? null;
        } catch (CalendlyException $e) {
            if ($e->calendlyStatus() === 409) {
                return $this->existingSubscription($connection, $url);
            }

            Log::warning('[CALENDLY] Webhook subscription not created', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function existingSubscription(CalendlyConnection $connection, string $url): ?string
    {
        $subscriptions = $this->client->get('webhook_subscriptions', [
            'organization' => $connection->calendly_organization_uri,
            'user' => $connection->calendly_user_uri,
            'scope' => 'user',
        ])['collection'] ?? [];

        return collect($subscriptions)->firstWhere('callback_url', $url)['uri'] ?? null;
    }
}
