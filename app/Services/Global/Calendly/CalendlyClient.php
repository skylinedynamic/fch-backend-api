<?php

namespace App\Services\Global\Calendly;

use App\Models\Calendly\CalendlyConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Calendly API v2, acting as one partner's connected account - bind it first
 * with forConnection().
 *
 * The access token lasts about two hours; it is refreshed (with the partner's own OAuth
 * app keys) shortly before it runs out, or once when
 * Calendly answers 401. Calendly's refresh tokens are single-use, so
 * refreshing is done under a lock and re-reads the connection first - two
 * requests never spend the same refresh token. The token is only ever sent to
 * the configured Calendly API host.
 */
class CalendlyClient
{
    private const REFRESH_MARGIN_SECONDS = 120;

    private const TIMEOUT_SECONDS = 20;

    private ?CalendlyConnection $bound = null;

    /** A client acting as this connection's account. */
    public function forConnection(?CalendlyConnection $connection): static
    {
        $client = clone $this;
        $client->bound = $connection;

        return $client;
    }

    /** The bound account; refused when there is none, or it is not (or no longer) signed in. */
    public function connection(): CalendlyConnection
    {
        $connection = $this->bound;

        if (! $connection || (! $connection->isActive() && $connection->status !== CalendlyConnection::STATUS_RECONNECT)) {
            throw CalendlyException::notConnected();
        }

        if (! $connection->isActive()) {
            throw CalendlyException::needsReconnect();
        }

        return $connection;
    }

    public function get(string $pathOrUri, array $query = []): array
    {
        return $this->request('GET', $pathOrUri, $query);
    }

    public function post(string $pathOrUri, array $body = []): array
    {
        return $this->request('POST', $pathOrUri, $body);
    }

    public function delete(string $pathOrUri): void
    {
        $this->request('DELETE', $pathOrUri);
    }

    /** A Calendly API path ("scheduled_events") or a resource URI Calendly gave us, as a URL on the API host. */
    public function url(string $pathOrUri): string
    {
        $base = rtrim((string) config('services.calendly.api_url'), '/');

        if (preg_match('#^https?://#i', $pathOrUri)) {
            if (! str_starts_with($pathOrUri, $base.'/')) {
                throw new InvalidArgumentException('Not a Calendly API address.');
            }

            return $pathOrUri;
        }

        return $base.'/'.ltrim($pathOrUri, '/');
    }

    /** A token for the connection, refreshed first when it is about to run out (or, with $failedToken, when Calendly refused that one). */
    public function accessToken(CalendlyConnection $connection, ?string $failedToken = null): string
    {
        if ($failedToken === null && ! $this->isExpiring($connection)) {
            return $connection->access_token;
        }

        return Cache::lock('calendly:token-refresh:'.$connection->id, 30)->block(15, function () use ($connection, $failedToken) {
            $connection->refresh();

            $stillValid = $failedToken === null ? ! $this->isExpiring($connection) : $connection->access_token !== $failedToken;
            if ($stillValid) {
                return $connection->access_token;
            }

            return $this->refreshTokens($connection);
        });
    }

    private function request(string $method, string $pathOrUri, array $data = []): array
    {
        $url = $this->url($pathOrUri);
        $connection = $this->connection();

        $token = $this->accessToken($connection);
        $response = $this->send($method, $url, $data, $token);

        if ($response->status() === 401) {
            $response = $this->send($method, $url, $data, $this->accessToken($connection, $token));
        }

        if ($response->failed()) {
            Log::warning('[CALENDLY] API request refused', [
                'method' => $method,
                'path' => parse_url($url, PHP_URL_PATH),
                'status' => $response->status(),
                'title' => $response->json('title'),
                // Which fields Calendly refused - names only, never the values sent.
                'refused_parameters' => collect($response->json('details') ?? [])->pluck('parameter')->filter()->values()->all() ?: null,
                // Names the missing scopes on a 403 "Insufficient scope".
                'required_scopes' => $response->json('details.required_scopes') ?? $response->json('required_scopes'),
                // A 429: Calendly's own word on its limit - when it takes requests again, and its message.
                'retry_after' => $response->status() === 429 ? (CalendlyException::secondsUntilRetry($response) ?? null) : null,
                'rate_limit' => $response->status() === 429 ? array_filter([
                    'limit' => $response->header('X-RateLimit-Limit'),
                    'remaining' => $response->header('X-RateLimit-Remaining'),
                    'message' => $response->json('message'),
                ]) ?: null : null,
            ]);

            // The token lacks a scope (granted before the scope was asked
            // for): only connecting again grants it, so say so on the card.
            if ($response->status() === 403 && $response->json('title') === 'Insufficient scope') {
                $connection->update(['status' => CalendlyConnection::STATUS_RECONNECT]);

                throw CalendlyException::needsReconnect();
            }

            throw CalendlyException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    private function send(string $method, string $url, array $data, string $token): Response
    {
        $http = Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT_SECONDS);

        return match ($method) {
            'GET' => $http->get($url, $data),
            'POST' => $http->asJson()->post($url, $data),
            'DELETE' => $http->delete($url),
        };
    }

    private function isExpiring(CalendlyConnection $connection): bool
    {
        return ! $connection->token_expires_at
            || $connection->token_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function refreshTokens(CalendlyConnection $connection): string
    {
        $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)
            ->post(rtrim((string) config('services.calendly.auth_url'), '/').'/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $connection->client_id,
                'client_secret' => $connection->client_secret,
            ]);

        if ($response->failed()) {
            Log::warning('[CALENDLY] Token refresh refused', ['status' => $response->status(), 'error' => $response->json('error')]);

            if (in_array($response->status(), [400, 401], true)) {
                $connection->update(['status' => CalendlyConnection::STATUS_RECONNECT]);

                throw CalendlyException::needsReconnect();
            }

            throw CalendlyException::fromResponse($response);
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?: 7200)),
            'status' => CalendlyConnection::STATUS_ACTIVE,
        ]);

        return $connection->access_token;
    }
}
