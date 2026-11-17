<?php

namespace App\Services\Global\Calendly;

use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connecting a partner's Calendly over OAuth 2.0 (authorization code grant)
 * with that partner's own Calendly OAuth app keys (saved in Settings >
 * Calendly), and disconnecting it.
 *
 * The state sent to Calendly is a random one-time value remembered for the
 * user and the connection; the callback - a browser arriving from Calendly,
 * with no API token - is only honoured with that value, once.
 */
class CalendlyOAuthService
{
    private const STATE_TTL_MINUTES = 15;

    private const STATE_CACHE_PREFIX = 'calendly:oauth-state:';

    public function __construct(
        private readonly CalendlyClient $client,
    ) {}

    /** A webhook signing key for the connection: the partner's own, else one FCH makes. */
    public function ensureSigningKey(CalendlyConnection $connection): void
    {
        if (blank($connection->webhook_signing_key)) {
            $connection->update(['webhook_signing_key' => Str::random(48)]);
        }
    }

    public function authorizeUrl(User $user, CalendlyConnection $connection): string
    {
        $state = Str::random(64);
        Cache::put(self::STATE_CACHE_PREFIX.$state, ['user_id' => $user->id, 'connection_id' => $connection->id], now()->addMinutes(self::STATE_TTL_MINUTES));

        return rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/authorize?'.http_build_query([
            'client_id' => $connection->client_id,
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

    /** Where Calendly posts this connection's bookings - one address per connection, checked with its own signing key. */
    public function webhookUrl(CalendlyConnection $connection): string
    {
        return rtrim((string) (config('services.calendly.webhook_url') ?: url('/api/v1/calendly/webhook')), '/').'/'.$connection->id;
    }

    /**
     * The user and connection the state was issued for - once; null for an
     * unknown, expired or used state, or one whose connection is gone.
     *
     * @return array{0: User, 1: CalendlyConnection}|null
     */
    public function consumeState(?string $state): ?array
    {
        if (! $state) {
            return null;
        }

        $issued = Cache::pull(self::STATE_CACHE_PREFIX.$state);
        $user = is_array($issued) ? User::find($issued['user_id'] ?? null) : null;
        $connection = is_array($issued) ? CalendlyConnection::find($issued['connection_id'] ?? null) : null;

        return $user && $connection && $connection->isManagedBy($user) ? [$user, $connection] : null;
    }

    /** Swaps the code for tokens and signs the partner's connection in. */
    public function connect(User $user, CalendlyConnection $connection, string $code): CalendlyConnection
    {
        $tokens = Http::asForm()->acceptJson()->timeout(20)
            ->post(rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $connection->client_id,
                'client_secret' => $connection->client_secret,
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

        // One Calendly account belongs to one partner: its meetings would otherwise land twice.
        if (CalendlyConnection::where('calendly_user_uri', $account['uri'] ?? '')->whereKeyNot($connection->id)->exists()) {
            throw CalendlyException::accountInUse();
        }

        $connection->update([
            'connected_by' => $user->id,
            'calendly_user_uri' => $account['uri'] ?? null,
            'calendly_organization_uri' => $account['current_organization'] ?? $tokens->json('organization'),
            'name' => $account['name'] ?? null,
            'email' => $account['email'] ?? null,
            'scheduling_url' => $account['scheduling_url'] ?? null,
            'timezone' => $account['timezone'] ?? null,
            'access_token' => $tokens->json('access_token'),
            'refresh_token' => $tokens->json('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) ($tokens->json('expires_in') ?: 7200)),
            'status' => CalendlyConnection::STATUS_ACTIVE,
        ]);

        $connection->update(['webhook_subscription_uri' => $this->subscribeToWebhooks($connection)]);

        return $connection->fresh();
    }

    /**
     * Registers the connection's webhook again - after its signing key was
     * changed in Settings > Calendly - so Calendly signs deliveries with the
     * key FCH now checks them against.
     */
    public function resubscribeWebhooks(CalendlyConnection $connection): void
    {
        if (! $connection->isActive()) {
            return;
        }

        if ($connection->webhook_subscription_uri) {
            try {
                $this->client->forConnection($connection)->delete($connection->webhook_subscription_uri);
            } catch (Throwable $e) {
                Log::warning('[CALENDLY] Old webhook subscription not removed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
            }
        }

        $connection->update(['webhook_subscription_uri' => $this->subscribeToWebhooks($connection)]);
    }

    /** Stops the webhooks and revokes the token; the meetings already brought in stay. */
    public function disconnect(?CalendlyConnection $connection): void
    {
        if (! $connection) {
            return;
        }

        if ($connection->webhook_subscription_uri && $connection->isActive()) {
            try {
                $this->client->forConnection($connection)->delete($connection->webhook_subscription_uri);
            } catch (Throwable $e) {
                Log::warning('[CALENDLY] Webhook subscription not removed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
            }
        }

        if (filled($connection->refresh_token)) {
            try {
                Http::asForm()->acceptJson()->timeout(20)
                    ->post(rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/revoke', [
                        'token' => $connection->refresh_token,
                        'client_id' => $connection->client_id,
                        'client_secret' => $connection->client_secret,
                    ]);
            } catch (Throwable $e) {
                Log::warning('[CALENDLY] Token not revoked', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
            }
        }

        $connection->update([
            'calendly_user_uri' => null,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'webhook_subscription_uri' => null,
            'status' => CalendlyConnection::STATUS_PENDING,
        ]);
    }

    /**
     * Subscribes the account's bookings and cancellations to this connection's
     * webhook address, signed with its own key; reuses the subscription when
     * Calendly already has one for it. An address Calendly cannot reach (a
     * laptop) leaves the account connected without webhooks - the hourly sync
     * still brings meetings in.
     */
    private function subscribeToWebhooks(CalendlyConnection $connection): ?string
    {
        $url = $this->webhookUrl($connection);
        $client = $this->client->forConnection($connection);

        try {
            return $client->post('webhook_subscriptions', array_filter([
                'url' => $url,
                'events' => CalendlySyncService::WEBHOOK_EVENTS,
                'organization' => $connection->calendly_organization_uri,
                'user' => $connection->calendly_user_uri,
                'scope' => 'user',
                'signing_key' => $connection->webhook_signing_key,
            ]))['resource']['uri'] ?? null;
        } catch (CalendlyException $e) {
            if ($e->calendlyStatus() === 409) {
                $subscriptions = $client->get('webhook_subscriptions', [
                    'organization' => $connection->calendly_organization_uri,
                    'user' => $connection->calendly_user_uri,
                    'scope' => 'user',
                ])['collection'] ?? [];

                return collect($subscriptions)->firstWhere('callback_url', $url)['uri'] ?? null;
            }

            Log::warning('[CALENDLY] Webhook subscription not created', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
