<?php

namespace Tests\Feature\Calendly;

use App\Http\Controllers\Api\V1\FCH\Common\Calendly\CalendlyController;
use App\Models\Appointment\Appointment;
use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesCalendly;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * POST /api/v1/calendly/webhook: Calendly telling us a meeting was booked or
 * cancelled on the company account. No login - the signature is the proof.
 */
#[CoversClass(CalendlyController::class)]
class CalendlyWebhookTest extends TestCase
{
    use FakesCalendly;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $host;

    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake();
        $this->configureCalendly();
        $hostPartner = $this->makeHostPartner();
        $this->host = $this->makePartnerAdmin($hostPartner);
        $this->borrowerUser = $this->makeBorrowerUser($hostPartner);
    }

    /** A delivery to a connection's own webhook address (the latest connection by default). */
    private function deliver(array $payload, ?string $signature = null, ?int $connectionId = null): TestResponse
    {
        $body = json_encode($payload);
        $connectionId ??= (int) CalendlyConnection::latest('id')->value('id');

        return $this->call('POST', "/api/v1/calendly/webhook/{$connectionId}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CALENDLY_WEBHOOK_SIGNATURE' => $signature ?? $this->calendlySignature($body),
        ], $body);
    }

    #[Test]
    public function a_booking_in_calendly_appears_for_the_host_and_the_borrower(): void
    {
        $this->connectCalendly($this->host);
        $event = $this->calendlyEvent('EV1', now()->addDays(3));

        $this->deliver($this->calendlyWebhook('invitee.created', $event, $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane')))
            ->assertOk();

        $appointment = Appointment::sole();
        $this->assertSame($this->host->id, (int) $appointment->scheduled_by);
        $this->assertSame($this->borrowerUser->id, (int) $appointment->assigned_for);

        $this->actingAsUser($this->borrowerUser);
        $this->postJson('/api/v1/common/appointments/fetch-all')->assertOk()
            ->assertJsonPath('data.0.is_calendly', true)
            ->assertJsonPath('data.0.join_url', 'https://meet.google.com/abc-defg-hij');
    }

    #[Test]
    public function a_cancellation_in_calendly_removes_the_appointment(): void
    {
        $this->connectCalendly($this->host);
        $event = $this->calendlyEvent('EV1', now()->addDays(3));
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane');
        $this->deliver($this->calendlyWebhook('invitee.created', $event, $invitee))->assertOk();

        $this->deliver($this->calendlyWebhook(
            'invitee.canceled',
            array_merge($event, ['status' => 'canceled']),
            array_merge($invitee, ['status' => 'canceled', 'cancellation' => ['canceled_by' => 'Jane', 'reason' => 'Conflict']])
        ))->assertOk();

        // Off the calendar (product owner, 2026-11-16); kept in the trash as cancelled.
        $this->assertSame(0, Appointment::count());
        $this->assertSame(Appointment::STATUS_CANCELLED, Appointment::onlyTrashed()->sole()->status);
    }

    #[Test]
    public function a_delivery_with_a_bad_signature_is_refused_and_changes_nothing(): void
    {
        $this->connectCalendly($this->host);
        $event = $this->calendlyEvent('EV1', now()->addDays(3));
        $payload = $this->calendlyWebhook('invitee.created', $event, $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane'));

        $this->deliver($payload, $this->calendlySignature(json_encode($payload), key: 'wrong-key'))->assertUnauthorized();
        $this->deliver($payload, 'garbage')->assertUnauthorized();

        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function an_event_we_do_not_handle_is_acknowledged_and_ignored(): void
    {
        $this->connectCalendly($this->host);
        $event = $this->calendlyEvent('EV1', now()->addDays(3));
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane');

        $this->deliver($this->calendlyWebhook('routing_form_submission.created', $event, $invitee))->assertOk();

        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function a_delivery_for_a_connection_that_does_not_exist_is_refused(): void
    {
        $event = $this->calendlyEvent('EV1', now()->addDays(3));

        $this->deliver($this->calendlyWebhook('invitee.created', $event, $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane')), null, 999)
            ->assertUnauthorized();

        $this->assertSame(0, Appointment::count());
    }

    /**
     * Each connection's webhook is checked with its own signing key: a
     * delivery signed with one partner's key is refused at another's address.
     */
    #[Test]
    public function a_delivery_signed_with_another_connections_key_is_refused(): void
    {
        $hostConnection = $this->connectCalendly($this->host);
        $partnerAdmin = $this->makePartnerAdmin($this->makeCertifiedPartner());
        $this->connectCalendly($partnerAdmin, [
            'calendly_user_uri' => "{$this->calendlyApi}/users/PARTNERUSER",
            'webhook_signing_key' => 'partner-signing-key',
        ]);
        $event = $this->calendlyEvent('EV1', now()->addDays(3));
        $payload = $this->calendlyWebhook('invitee.created', $event, $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane'));

        $this->deliver($payload, $this->calendlySignature(json_encode($payload), key: 'partner-signing-key'), $hostConnection->id)
            ->assertUnauthorized();

        $this->assertSame(0, Appointment::count());
    }
}
