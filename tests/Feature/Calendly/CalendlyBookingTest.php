<?php

namespace Tests\Feature\Calendly;

use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Http\Controllers\Api\V1\FCH\Common\Calendly\CalendlyController;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesCalendly;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Booking into the company Calendly from FCH: a borrower or partner books a
 * slot with the host, or the host books one with them. The booking is made in
 * Calendly (Scheduling API, POST /invitees) - so it is in Calendly and on the
 * host's linked calendar - and kept as an appointment both of them see.
 * Cancelling it in FCH cancels it in Calendly too.
 */
#[CoversClass(CalendlyController::class)]
#[CoversClass(AppointmentController::class)]
class CalendlyBookingTest extends TestCase
{
    use FakesCalendly;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $host;

    private User $borrowerUser;

    private User $partnerAdmin;

    private Carbon $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->configureCalendly();
        $hostPartner = $this->makeHostPartner();
        $this->host = $this->makePartnerAdmin($hostPartner);
        $this->borrowerUser = $this->makeBorrowerUser($hostPartner, [], ['name' => 'Jane Borrower']);
        $this->partnerAdmin = $this->makePartnerAdmin($this->makeCertifiedPartner());
        $this->slot = now()->addDays(3)->setTime(15, 0)->utc();
    }

    /** Calendly accepting a booking at $this->slot for $email. */
    private function fakeBooking(string $email, string $name, array $extra = []): array
    {
        $event = $this->calendlyEvent('EVBOOK', $this->slot);
        $invitee = $this->calendlyInvitee($event, $email, $name);

        Http::fake($extra + [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/invitees" => Http::response(['resource' => $invitee], 201),
            "{$this->calendlyApi}/scheduled_events/EVBOOK" => Http::response(['resource' => $event]),
        ]);

        return [$event, $invitee];
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'event_type_uri' => $this->calendlyEventTypeUri,
            'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'),
            'timezone' => 'America/Chicago',
            'subject' => 'Funding review',
            'description' => 'Bring statements',
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
        ], $overrides);
    }

    #[Test]
    public function the_event_types_of_the_company_calendly_are_listed(): void
    {
        $this->connectCalendly($this->host);
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(),
                $this->calendlyEventType(['uri' => "{$this->calendlyApi}/event_types/OFF", 'active' => false]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->borrowerUser);

        $this->getJson('/api/v1/common/calendly/event-types')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uri', $this->calendlyEventTypeUri)
            ->assertJsonPath('data.0.duration', 30);
    }

    #[Test]
    public function open_slots_are_shown_in_the_viewers_zone_without_those_already_taken_in_fch(): void
    {
        $this->connectCalendly($this->host);
        $taken = now()->addDays(3)->setTime(16, 0)->utc();
        // An FCH-only meeting of the borrower's at 16:00 UTC - Calendly does not know about it.
        Appointment::create([
            'appointment_code' => 'APP-0001', 'subject' => 'Other', 'appointment_date' => $taken->format('Y-m-d H:i:s'),
            'timezone' => 'UTC', 'duration_minutes' => 30, 'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_by' => $this->partnerAdmin->id, 'assigned_for' => $this->borrowerUser->id,
        ]);
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/event_type_available_times?*" => Http::response(['collection' => [
                ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
                ['status' => 'available', 'start_time' => $taken->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
            ]]),
        ]);
        $this->actingAsUser($this->borrowerUser);

        $response = $this->postJson('/api/v1/common/calendly/available-times', [
            'event_type_uri' => $this->calendlyEventTypeUri,
            'date' => $this->slot->copy()->setTimezone('America/Chicago')->format('Y-m-d'),
            'timezone' => 'America/Chicago',
        ])->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.start_time', $this->slot->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.0.local_time', $this->slot->copy()->setTimezone('America/Chicago')->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/event_type_available_times")
            && $request['event_type'] === $this->calendlyEventTypeUri);
    }

    #[Test]
    public function a_borrower_books_a_slot_with_the_host_and_it_is_made_in_calendly(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk()
            ->assertJsonPath('data.is_calendly', true)
            ->assertJsonPath('data.subject', 'Funding review');

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request->method() === 'POST'
            && $request['event_type'] === $this->calendlyEventTypeUri
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z')
            && $request['invitee']['email'] === $this->borrowerUser->email
            && $request['invitee']['name'] === 'Jane Borrower'
            && $request['invitee']['timezone'] === 'America/Chicago'
            && $request['location']['kind'] === 'google_conference');

        $appointment = Appointment::sole();
        $this->assertSame($this->host->id, (int) $appointment->scheduled_by);
        $this->assertSame($this->borrowerUser->id, (int) $appointment->assigned_for);
        $this->assertSame('Funding review', $appointment->subject);
        $this->assertSame('Bring statements', $appointment->description);
        $this->assertSame(Appointment::MEETING_TYPE_BUSINESS_REVIEW, $appointment->meeting_type);
        $this->assertSame($this->slot->format('Y-m-d H:i:s'), $appointment->appointment_date);
    }

    #[Test]
    public function the_host_books_a_slot_with_a_borrower_who_is_then_the_invitee(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => $this->borrowerUser->id]))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['invitee']['email'] === $this->borrowerUser->email);
        $this->assertSame($this->borrowerUser->id, (int) Appointment::sole()->assigned_for);
    }

    #[Test]
    public function the_host_must_say_who_the_meeting_is_with(): void
    {
        $this->connectCalendly($this->host);
        Http::fake();
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertStatus(422)->assertJsonValidationErrors('assigned_for');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function someone_who_may_not_book_with_the_host_cannot_book_into_its_calendly(): void
    {
        $this->connectCalendly($this->host);
        $otherBorrower = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        Http::fake();
        $this->actingAsUser($otherBorrower);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertForbidden();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function an_event_type_that_is_not_the_companys_is_refused(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['event_type_uri' => "{$this->calendlyApi}/event_types/NOT-OURS"]))
            ->assertStatus(422);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function booking_when_calendly_is_not_connected_says_so(): void
    {
        Http::fake();
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertStatus(422);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_free_plan_daily_booking_limit_is_explained(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/invitees" => Http::response(['title' => 'Too Many Requests', 'message' => 'Rate limit exceeded'], 429),
        ]);
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())
            ->assertStatus(429)
            ->assertJsonPath('message', __('calendly.rate_limited'));
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function a_slot_calendly_refuses_returns_calendlys_reason(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/invitees" => Http::response(['title' => 'Invalid Argument', 'message' => 'The selected time is no longer available.'], 400),
        ]);
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected time is no longer available.');
    }

    #[Test]
    public function cancelling_a_calendly_meeting_in_fch_cancels_it_in_calendly(): void
    {
        $this->connectCalendly($this->host);
        [$event] = $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation" => Http::response(['resource' => ['canceled_by' => 'Host']], 201),
        ]);
        $this->actingAsUser($this->borrowerUser);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->json('data.id');

        $this->postJson("/api/v1/common/appointments/{$id}/cancel")->assertOk();

        $this->assertSame(Appointment::STATUS_CANCELLED, Appointment::find($id)->status);
        Http::assertSent(fn (Request $request) => $request->url() === "{$event['uri']}/cancellation" && $request->method() === 'POST');
    }

    #[Test]
    public function a_cancellation_calendly_refuses_leaves_the_meeting_as_it_was(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation" => Http::response(['title' => 'Server Error', 'message' => 'Down'], 500),
        ]);
        $this->actingAsUser($this->borrowerUser);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->json('data.id');

        $this->postJson("/api/v1/common/appointments/{$id}/cancel")->assertStatus(502);

        $this->assertSame(Appointment::STATUS_CONFIRMED, Appointment::find($id)->status);
    }

    #[Test]
    public function a_calendly_meetings_time_is_changed_by_rescheduling_in_calendly_not_by_editing(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => $this->borrowerUser->id]))->json('data.id');

        // The edit form sends the whole meeting back, as the portals do.
        $form = [
            'subject' => 'Funding review',
            'appointment_date' => $this->slot->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
            'assigned_for' => $this->borrowerUser->id,
        ];

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, [
            'appointment_date' => now()->addDays(9)->format('Y-m-d H:i:s'),
        ]))->assertStatus(422)->assertJsonValidationErrors('appointment_date');
        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['duration_minutes' => 60]))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_date');

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['subject' => 'Renamed']))->assertOk();
        $this->assertSame('Renamed', Appointment::find($id)->subject);
    }
}
