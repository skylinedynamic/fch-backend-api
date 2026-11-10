<?php

namespace Tests\Support;

use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use Carbon\CarbonInterface;

/**
 * Calendly as the tests see it: the one connected company account and the
 * shapes Calendly's API v2 sends - scheduled events, invitees and webhook
 * deliveries. Every call to Calendly is faked with Http::fake by the test.
 */
trait FakesCalendly
{
    protected string $calendlyApi = 'https://api.calendly.com';

    protected string $calendlyAuth = 'https://auth.calendly.com';

    protected string $calendlyUserUri = 'https://api.calendly.com/users/HOSTUSER';

    protected string $calendlyOrgUri = 'https://api.calendly.com/organizations/HOSTORG';

    protected string $calendlyEventTypeUri = 'https://api.calendly.com/event_types/ET1';

    protected string $calendlySigningKey = 'test-signing-key';

    protected function configureCalendly(): void
    {
        config([
            'services.calendly.client_id' => 'test-client-id',
            'services.calendly.client_secret' => 'test-client-secret',
            'services.calendly.webhook_signing_key' => $this->calendlySigningKey,
            'services.calendly.redirect_uri' => 'https://api.example.test/api/v1/calendly/callback',
            'services.calendly.webhook_url' => 'https://api.example.test/api/v1/calendly/webhook',
            'services.calendly.auth_url' => $this->calendlyAuth,
            'services.calendly.api_url' => $this->calendlyApi,
            'app.frontend_partner_url' => 'https://partner.example.test',
        ]);
    }

    protected function connectCalendly(User $host, array $attributes = []): CalendlyConnection
    {
        return CalendlyConnection::create(array_merge([
            'connected_by' => $host->id,
            'calendly_user_uri' => $this->calendlyUserUri,
            'calendly_organization_uri' => $this->calendlyOrgUri,
            'name' => 'Host Calendly',
            'email' => 'host-calendly@example.test',
            'scheduling_url' => 'https://calendly.com/host',
            'timezone' => 'America/New_York',
            'access_token' => 'access-token-1',
            'refresh_token' => 'refresh-token-1',
            'token_expires_at' => now()->addHour(),
            'status' => CalendlyConnection::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function calendlyEvent(string $id, CarbonInterface $start, array $overrides = []): array
    {
        return array_merge([
            'uri' => "{$this->calendlyApi}/scheduled_events/{$id}",
            'name' => '30 Minute Meeting',
            'status' => 'active',
            'start_time' => $start->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'end_time' => $start->copy()->utc()->addMinutes(30)->format('Y-m-d\TH:i:s.u\Z'),
            'event_type' => $this->calendlyEventTypeUri,
            'location' => ['type' => 'google_conference', 'join_url' => 'https://meet.google.com/abc-defg-hij', 'status' => 'pushed'],
            'event_memberships' => [['user' => $this->calendlyUserUri, 'user_email' => 'host-calendly@example.test', 'user_name' => 'Host Calendly']],
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ], $overrides);
    }

    protected function calendlyInvitee(array $event, string $email, string $name, array $overrides = []): array
    {
        $id = 'INV-'.md5($event['uri'].$email);

        return array_merge([
            'uri' => "{$event['uri']}/invitees/{$id}",
            'email' => $email,
            'name' => $name,
            'status' => 'active',
            'timezone' => 'America/Chicago',
            'event' => $event['uri'],
            'cancel_url' => "https://calendly.com/cancellations/{$id}",
            'reschedule_url' => "https://calendly.com/reschedulings/{$id}",
            'rescheduled' => false,
            'old_invitee' => null,
            'new_invitee' => null,
            'questions_and_answers' => [],
        ], $overrides);
    }

    protected function calendlyEventType(array $overrides = []): array
    {
        return array_merge([
            'uri' => $this->calendlyEventTypeUri,
            'name' => '30 Minute Meeting',
            'active' => true,
            'duration' => 30,
            'kind' => 'solo',
            'scheduling_url' => 'https://calendly.com/host/30min',
            'locations' => [['kind' => 'google_conference']],
        ], $overrides);
    }

    /** A webhook delivery, as Calendly posts it. */
    protected function calendlyWebhook(string $type, array $event, array $invitee): array
    {
        return [
            'event' => $type,
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'created_by' => $this->calendlyUserUri,
            'payload' => array_merge($invitee, ['scheduled_event' => $event]),
        ];
    }

    /** The Calendly-Webhook-Signature header for $body: t=<time>,v1=<hmac of "t.body">. */
    protected function calendlySignature(string $body, ?int $timestamp = null, ?string $key = null): string
    {
        $timestamp ??= now()->timestamp;
        $hash = hash_hmac('sha256', $timestamp.'.'.$body, $key ?? $this->calendlySigningKey);

        return "t={$timestamp},v1={$hash}";
    }
}
