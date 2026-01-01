<?php

namespace App\Services\Global\Microsoft;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MicrosoftCalendarService
{
    private const TOKEN_CACHE_KEY = 'ms_graph_calendar_token';

    /**
     * Get the configured Login Endpoint with a guaranteed trailing slash.
     */
    private function getLoginEndpoint(): string
    {
        // Defaults are defined in config/services.php
        $endpoint = config('services.microsoft.login_endpoint');
        return Str::finish($endpoint, '/');
    }

    /**
     * Get the configured Graph Endpoint guaranteed WITHOUT a trailing slash.
     */
    private function getGraphEndpoint(): string
    {
        // Defaults are defined in config/services.php
        $endpoint = config('services.microsoft.graph_endpoint');
        return rtrim($endpoint, '/');
    }

    /**
     * Get Access Token
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3500, function () {
            $response = Http::asForm()->post(
                $this->getLoginEndpoint() . config('services.microsoft.tenant_id') . "/oauth2/v2.0/token",
                [
                    'client_id'     => config('services.microsoft.client_id'),
                    'client_secret' => config('services.microsoft.client_secret'),
                    'scope'         => config('services.microsoft.default_scope'),
                    'grant_type'    => 'client_credentials',
                ]
            );

            if (!$response->successful()) {
                Log::error('[MS GRAPH] Token request failed', ['response' => $response->body()]);
                throw new \RuntimeException('Failed to get access token: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Prepare event payload
     */
    private function eventPayload(array $data = []): array
    {
        $now = now();
        
        $payload = [
            'subject' => $data['subject'] ?? 'Meeting',
            'body' => $data['body'] ?? [
                'contentType' => 'HTML',
                'content' => $data['description'] ?? '',
            ],
            'start' => $data['start'] ?? [
                'dateTime' => $now->addHour()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'end' => $data['end'] ?? [
                'dateTime' => $now->addHours(2)->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'location' => $data['location'] ?? [
                'displayName' => 'Online Meeting',
            ],
        ];

        // Add optional fields if present
        if (isset($data['attendees'])) {
            $payload['attendees'] = $data['attendees'];
        }

        if (isset($data['isOnlineMeeting'])) {
            $payload['isOnlineMeeting'] = $data['isOnlineMeeting'];
        }

        return $payload;
    }

    /** 
     * CREATE EVENT 
     * 
     * @param string $userEmail The email of the user (or service account) to create the event for.
     * @param array $data The event data.
     */
    public function createEvent(string $userEmail, array $data = []): string
    {
        if (empty($userEmail)) {
            throw new \InvalidArgumentException('Target user email is required for Microsoft Graph event creation.');
        }

        $payload = $this->eventPayload($data);
        $response = Http::withToken($this->accessToken())
            ->post($this->getGraphEndpoint() . "/users/{$userEmail}/calendar/events", $payload);

        if (!$response->successful()) {
            Log::error('[MS GRAPH] Create event failed', [
                'user' => $userEmail,
                'response' => $response->json(),
                'status' => $response->status()
            ]);
            // throw new \RuntimeException('Failed to create event: ' . $response->body());
        }

        Log::info('[MS GRAPH] Event created successfully', ['user' => $userEmail, 'eventId' => $response->json('id')]);
        // return $response->json('id');
        return true;
    }

    /** 
     * UPDATE EVENT 
     * 
     * @param string $userEmail The email of the user (or service account).
     * @param string $eventId The ID of the event to update.
     * @param array $data The new event data.
     */
    public function updateEvent(string $userEmail, string $eventId, array $data = []): bool
    {
        if (empty($userEmail) || empty($eventId)) {
            return false;
        }

        $response = Http::withToken($this->accessToken())
            ->patch($this->getGraphEndpoint() . "/users/{$userEmail}/calendar/events/{$eventId}", $this->eventPayload($data));

        if (!$response->successful()) {
            Log::error('[MS GRAPH] Update event failed', [
                'user' => $userEmail,
                'eventId' => $eventId,
                'response' => $response->json()
            ]);
            return false;
        }

        Log::info('[MS GRAPH] Event updated successfully', ['user' => $userEmail, 'eventId' => $eventId]);
        return true;
    }

    /** 
     * DELETE EVENT 
     * 
     * @param string $userEmail The email of the user (or service account).
     * @param string $eventId The ID of the event to delete.
     */
    public function deleteEvent(string $userEmail, string $eventId): bool
    {
        if (empty($userEmail) || empty($eventId)) {
            return false;
        }

        $response = Http::withToken($this->accessToken())
            ->delete($this->getGraphEndpoint() . "/users/{$userEmail}/calendar/events/{$eventId}");

        $success = $response->successful() || $response->status() === 404;
        Log::info('[MS GRAPH] Event deletion', ['user' => $userEmail, 'eventId' => $eventId, 'success' => $success]);

        return $success;
    }
}
