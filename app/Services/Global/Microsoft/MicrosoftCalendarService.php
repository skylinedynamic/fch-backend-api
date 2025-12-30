<?php

namespace App\Services\Global\Microsoft;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MicrosoftCalendarService
{
    protected function getTenantId(): string
    {
        return config('services.microsoft.tenant_id');
    }

    protected function getClientId(): string
    {
        return config('services.microsoft.client_id');
    }

    protected function getClientSecret(): string
    {
        return config('services.microsoft.client_secret');
    }

    protected function tokenCacheKey(): string
    {
        return 'ms_graph_calendar_access_token';
    }

    /**
     * Fetch or cache Microsoft Graph access token
     */
    public function getAccessToken(): string
    {
        if (Cache::has($this->tokenCacheKey())) {
            return Cache::get($this->tokenCacheKey());
        }

        $url = "https://login.microsoftonline.com/{$this->getTenantId()}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->withoutVerifying() // only for local dev, fix cacert.pem for prod
                ->timeout(10)
                ->post($url, [
                    'client_id'     => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('[MS Graph] Token request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \RuntimeException('Microsoft Graph token request failed.');
            }

            $data = $response->json();
            Cache::put($this->tokenCacheKey(), $data['access_token'], now()->addSeconds($data['expires_in'] - 60));

            return $data['access_token'];
        } catch (\Exception $e) {
            Log::error('[MS Graph] Unable to fetch token', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Unable to connect to Microsoft Graph.');
        }
    }

    /**
     * Create a Microsoft 365 calendar event
     */
    public function createEvent(string $userIdOrEmail, array $eventData): ?string
    {
        $token = $this->getAccessToken();
        $encodedUser = 'arafat.test@sajib123arafatgmail.onmicrosoft.com';

        $url = "https://graph.microsoft.com/v1.0/users/{$encodedUser}/calendar/events";

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(10)
                ->post($url, $eventData);

                dd($response,$token);
            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
            }

            if ($response->successful()) {
                $id = $response->json()['id'] ?? null;
                Log::info('[MS Graph] Event created', [
                    'user' => $userIdOrEmail,
                    'event_id' => $id
                ]);
                return $id;
            }

            Log::error('[MS Graph] Event creation failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'user' => $userIdOrEmail
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('[MS Graph] Event creation exception', [
                'error' => $e->getMessage(),
                'user' => $userIdOrEmail
            ]);
            return null;
        }
    }

    /**
     * Update an event
     */
    public function updateEvent(string $userIdOrEmail, string $eventId, array $eventData): bool
    {
        $token = $this->getAccessToken();
        $encodedUser = rawurlencode($userIdOrEmail);
        $url = "https://graph.microsoft.com/v1.0/users/{$encodedUser}/calendar/events/{$eventId}";

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(10)
                ->patch($url, $eventData);

            if ($response->successful()) {
                Log::info('[MS Graph] Event updated', [
                    'user' => $userIdOrEmail,
                    'event_id' => $eventId
                ]);
                return true;
            }

            Log::error('[MS Graph] Event update failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user' => $userIdOrEmail,
                'event_id' => $eventId
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('[MS Graph] Event update exception', [
                'error' => $e->getMessage(),
                'user' => $userIdOrEmail,
                'event_id' => $eventId
            ]);
            return false;
        }
    }

    /**
     * Delete an event
     */
    public function deleteEvent(string $userIdOrEmail, string $eventId): bool
    {
        $token = $this->getAccessToken();
        $encodedUser = rawurlencode($userIdOrEmail);
        $url = "https://graph.microsoft.com/v1.0/users/{$encodedUser}/calendar/events/{$eventId}";

        try {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(10)
                ->delete($url);

            if ($response->successful() || $response->status() === 404) {
                Log::info('[MS Graph] Event deleted', [
                    'user' => $userIdOrEmail,
                    'event_id' => $eventId
                ]);
                return true;
            }

            Log::error('[MS Graph] Event delete failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user' => $userIdOrEmail,
                'event_id' => $eventId
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('[MS Graph] Event delete exception', [
                'error' => $e->getMessage(),
                'user' => $userIdOrEmail,
                'event_id' => $eventId
            ]);
            return false;
        }
    }
}
