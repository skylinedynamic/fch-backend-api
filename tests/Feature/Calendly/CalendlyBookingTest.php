<?php

namespace Tests\Feature\Calendly;

use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Http\Controllers\Api\V1\FCH\Common\Calendly\CalendlyController;
use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private function fakeBooking(string $email, string $name, array $extra = [], array $eventOverrides = []): array
    {
        $event = $this->calendlyEvent('EVBOOK', $this->slot, $eventOverrides);
        $invitee = $this->calendlyInvitee($event, $email, $name);

        Http::fake($extra + [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            // The picked time is open in Calendly.
            "{$this->calendlyApi}/event_type_available_times?*" => Http::response(['collection' => [
                ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
            ]]),
            "{$this->calendlyApi}/invitees" => Http::response(['resource' => $invitee], 201),
            "{$this->calendlyApi}/scheduled_events/EVBOOK" => Http::response(['resource' => $event]),
            // No event_types:write by default: the meeting is booked on the event type.
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['title' => 'Permission Denied', 'message' => 'Insufficient scope'], 403),
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
            // Everyone books into their own Calendly and invites the person they pick.
            'assigned_for' => $this->borrowerUser->id,
        ], $overrides);
    }

    /** Add Appointment: just the date and time picked, in the chosen zone - Calendly's active event type is used. */
    #[Test]
    public function a_picked_date_and_time_is_booked_with_the_accounts_active_event_type(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);
        $local = $this->slot->copy()->setTimezone('America/Chicago');

        $payload = $this->bookingPayload(['start_time' => $local->format('Y-m-d H:i:s')]);
        unset($payload['event_type_uri']);
        $this->postJson('/api/v1/common/calendly/book', $payload)->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === $this->calendlyEventTypeUri
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z'));
    }

    #[Test]
    public function a_time_not_open_in_calendly_is_refused_with_the_open_times_before_calendly_is_asked_to_book(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);
        $closed = $this->slot->copy()->addHours(5)->setTimezone('America/Chicago');

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['start_time' => $closed->format('Y-m-d H:i:s')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time')
            ->assertJsonFragment([__('calendly.time_not_open', [
                'time' => $closed->format('g:i A'),
                'times' => $this->slot->copy()->setTimezone('America/Chicago')->format('g:i A'),
            ])]);

        Http::assertNotSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees");
        $this->assertSame(0, Appointment::count());
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
        $this->actingAsUser($this->host);

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
        $this->actingAsUser($this->host);

        $response = $this->postJson('/api/v1/common/calendly/available-times', [
            'event_type_uri' => $this->calendlyEventTypeUri,
            'date' => $this->slot->copy()->setTimezone('America/Chicago')->format('Y-m-d'),
            'timezone' => 'America/Chicago',
            'assigned_for' => $this->borrowerUser->id,
        ])->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.start_time', $this->slot->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.0.local_time', $this->slot->copy()->setTimezone('America/Chicago')->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/event_type_available_times")
            && $request['event_type'] === $this->calendlyEventTypeUri);
    }

    /**
     * The person invited has a Calendly of their own: a time busy there (a meeting
     * in it, or on a calendar connected to it) is not offered - asked of their
     * Calendly (user_busy_times), with their own sign-in.
     */
    #[Test]
    public function a_time_busy_in_the_invited_persons_own_calendly_is_not_offered(): void
    {
        $this->connectCalendly($this->host);
        $this->connectCalendly($this->borrowerUser, [
            'borrower_id' => $this->borrowerUser->entity_id,
            'calendly_user_uri' => "{$this->calendlyApi}/users/BORROWERUSER",
            'access_token' => 'borrower-access-token',
        ]);
        $busy = now()->addDays(3)->setTime(16, 0)->utc();
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/event_type_available_times?*" => Http::response(['collection' => [
                ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
                ['status' => 'available', 'start_time' => $busy->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
            ]]),
            // The borrower's own Calendly: busy 16:00-16:30 (e.g. a meeting on their Google calendar).
            "{$this->calendlyApi}/user_busy_times?*" => Http::response(['collection' => [
                ['type' => 'external', 'start_time' => $busy->format('Y-m-d\TH:i:s\Z'), 'end_time' => $busy->copy()->addMinutes(30)->format('Y-m-d\TH:i:s\Z')],
            ]]),
        ]);
        $this->actingAsUser($this->host);

        $r = $this->postJson('/api/v1/common/calendly/available-times', [
            'event_type_uri' => $this->calendlyEventTypeUri,
            'date' => $this->slot->copy()->setTimezone('America/Chicago')->format('Y-m-d'),
            'timezone' => 'America/Chicago',
            'assigned_for' => $this->borrowerUser->id,
        ]);
        $r->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.start_time', $this->slot->format('Y-m-d\TH:i:s\Z'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/user_busy_times")
            && $request['user'] === "{$this->calendlyApi}/users/BORROWERUSER"
            && $request->hasHeader('Authorization', 'Bearer borrower-access-token'));
    }

    #[Test]
    public function the_host_books_a_slot_in_its_own_calendly_and_it_is_made_in_calendly(): void
    {
        $connection = $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);

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
        $this->assertSame($connection->id, (int) $appointment->calendly_connection_id);
    }

    /**
     * Calendly names a meeting after its event type and takes no other name:
     * the FCH title and description go in the event type's first free-text
     * question, and the FCH appointment keeps its own title.
     */
    #[Test]
    public function the_appointment_title_and_description_are_sent_to_calendly_in_its_question(): void
    {
        $question = 'Please share anything that will help prepare for our meeting.';
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType(['custom_questions' => [
                ['name' => 'Pick one', 'type' => 'single_select', 'enabled' => true, 'position' => 0],
                ['name' => $question, 'type' => 'text', 'enabled' => true, 'position' => 1],
            ]])], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['description' => '<p>Bring &amp; review statements</p>']))
            ->assertOk()
            ->assertJsonPath('data.subject', 'Funding review');

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['questions_and_answers'] === [[
                'question' => $question,
                'answer' => "Funding review\n\nBring & review statements",
                'position' => 0,
            ]]);
        $this->assertSame('Funding review', Appointment::sole()->subject);
    }

    /**
     * With event_types:write, the meeting is named after the FCH title: a
     * one-off meeting type with that name (same length and day) is made and
     * the invitee booked on it.
     */
    #[Test]
    public function the_meeting_is_named_after_the_appointment_title_in_calendly(): void
    {
        $this->connectCalendly($this->host);
        $oneOff = "{$this->calendlyApi}/event_types/ONEOFF1";
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['resource' => ['uri' => $oneOff]], 201),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk()
            ->assertJsonPath('data.subject', 'Funding review');

        $day = $this->slot->copy()->setTimezone('America/Chicago');
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/one_off_event_types"
            && $request['name'] === 'Funding review'
            && $request['host'] === $this->calendlyUserUri
            && $request['duration'] === 30
            && $request['date_setting'] === ['type' => 'date_range', 'start_date' => $day->toDateString(), 'end_date' => $day->copy()->addDay()->toDateString()]
            && $request['location'] === ['kind' => 'google_conference']);
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === $oneOff
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z')
            && ! isset($request['questions_and_answers']));
        Http::assertNotSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === $this->calendlyEventTypeUri);
    }

    /**
     * A phone-call event type: Calendly will not book a one-off with "custom" or
     * "ask invitee" places, so the call is written out as its place, with the FCH
     * description as the place's additional info - both shown in Calendly.
     */
    #[Test]
    public function a_named_phone_call_meeting_shows_the_number_and_description_in_calendly(): void
    {
        Borrower::whereKey($this->borrowerUser->entity_id)->update(['phone_number' => '3125550199', 'country_code' => '+1']);
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['resource' => ['uri' => "{$this->calendlyApi}/event_types/ONEOFF9"]], 201),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['description' => '<p>Bring the statements</p>']))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/one_off_event_types"
            && $request['name'] === 'Funding review'
            && $request['location'] === ['kind' => 'physical', 'location' => 'Phone call: +13125550199', 'additional_info' => 'Bring the statements']);
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === "{$this->calendlyApi}/event_types/ONEOFF9"
            && ! isset($request['location']));
    }

    /** Calendly refusing the named meeting (no event_types:write, plan...): booked on the event type as before. */
    #[Test]
    public function a_refused_named_meeting_is_booked_on_the_event_type_instead(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['title' => 'Permission Denied', 'message' => 'Insufficient scope'], 403),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === $this->calendlyEventTypeUri);
        $this->assertSame(1, Appointment::count());
    }

    /** Calendly failing (not refusing) mid-way: not booked again on the event type - it may already be booked. */
    #[Test]
    public function a_calendly_failure_while_naming_the_meeting_is_not_booked_twice(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['resource' => ['uri' => "{$this->calendlyApi}/event_types/ONEOFF1"]], 201),
            "{$this->calendlyApi}/invitees" => Http::response(['message' => 'Down'], 500),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertStatus(502);

        // Event types, open times, the named meeting type, its one booking - nothing more.
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === $this->calendlyEventTypeUri);
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function an_event_type_without_a_text_question_is_booked_without_answers(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && ! isset($request['questions_and_answers']));
    }

    /** A borrower with their own Calendly books in it, inviting the advisor they picked. */
    #[Test]
    public function a_borrower_with_their_own_calendly_books_in_it_inviting_who_they_pick(): void
    {
        $this->connectCalendly($this->host);
        $own = $this->connectCalendly($this->borrowerUser, [
            'borrower_id' => $this->borrowerUser->entity_id,
            'calendly_user_uri' => "{$this->calendlyApi}/users/BORROWERUSER",
            'access_token' => 'borrower-access-token',
        ]);
        // The meeting is on the borrower's own Calendly user.
        $this->fakeBooking($this->host->email, $this->host->name, [], ['event_memberships' => [
            ['user' => "{$this->calendlyApi}/users/BORROWERUSER", 'user_email' => $this->borrowerUser->email, 'user_name' => $this->borrowerUser->name],
        ]]);
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => $this->host->id]))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request->hasHeader('Authorization', 'Bearer borrower-access-token')
            && $request['invitee']['email'] === $this->host->email);
        $appointment = Appointment::sole();
        $this->assertSame($this->borrowerUser->id, (int) $appointment->scheduled_by);
        $this->assertSame($this->host->id, (int) $appointment->assigned_for);
        $this->assertSame($own->id, (int) $appointment->calendly_connection_id);
    }

    /** A borrower books with their partner's Calendly (here the host's), as the invitee - picking nobody. */
    #[Test]
    public function a_borrower_books_with_their_partners_calendly_as_the_invitee(): void
    {
        $connection = $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => null]))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['invitee']['email'] === $this->borrowerUser->email);
        $appointment = Appointment::sole();
        $this->assertSame($this->host->id, (int) $appointment->scheduled_by);
        $this->assertSame($this->borrowerUser->id, (int) $appointment->assigned_for);
        $this->assertSame($connection->id, (int) $appointment->calendly_connection_id);
    }

    /** Tenant-wise: a WLP's borrower books with that WLP's Calendly only - never the host's. */
    #[Test]
    public function a_borrower_whose_partner_has_not_connected_calendly_cannot_book_with_another_partners(): void
    {
        $this->connectCalendly($this->host);
        $wlpBorrower = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        Http::fake();
        $this->actingAsUser($wlpBorrower);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => null]))->assertStatus(422);
        $this->getJson('/api/v1/common/calendly/status')->assertJsonPath('data.can_book', false);

        Http::assertNothingSent();
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function the_booker_must_say_who_the_meeting_is_with(): void
    {
        $this->connectCalendly($this->host);
        Http::fake();
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => null]))->assertStatus(422)->assertJsonValidationErrors('assigned_for');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function the_person_invited_must_be_one_the_booker_may_book_with(): void
    {
        // A certified partner's team inviting a borrower of the host - another tenant.
        $connection = $this->connectCalendly($this->partnerAdmin, ['calendly_user_uri' => "{$this->calendlyApi}/users/PARTNERUSER"]);
        Http::fake();
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => $this->borrowerUser->id]))
            ->assertStatus(422)->assertJsonValidationErrors('assigned_for');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
        $this->assertTrue($connection->fresh()->isActive());
    }

    #[Test]
    public function the_event_types_say_when_the_invitee_must_give_where_to_meet(): void
    {
        $this->connectCalendly($this->host);
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(),
                $this->calendlyEventType(['uri' => "{$this->calendlyApi}/event_types/CALL", 'locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->getJson('/api/v1/common/calendly/event-types')->assertOk()
            ->assertJsonPath('data.0.location_kind', 'google_conference')
            ->assertJsonPath('data.0.asks_invitee_location', false)
            ->assertJsonPath('data.1.location_kind', 'outbound_call')
            ->assertJsonPath('data.1.asks_invitee_location', true);
    }

    /**
     * Defect: an event type set to "Phone call - I will call my invitee" could
     * not be booked. Calendly requires the invitee's number with such a
     * booking ("location.location is required when location.kind is
     * 'outbound_call'"), and only the kind was sent - so every booking was
     * refused with a 400.
     */
    /** Every place an event type offers is listed, with whether the invitee must say where. */
    #[Test]
    public function the_event_types_list_every_location_they_offer(): void
    {
        $this->connectCalendly($this->host);
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [
                    ['kind' => 'zoom_conference'],
                    ['kind' => 'outbound_call'],
                    ['kind' => 'physical', 'location' => '1 Main St'],
                ]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->getJson('/api/v1/common/calendly/event-types')->assertOk()
            ->assertJsonPath('data.0.location_options.0', ['kind' => 'zoom_conference', 'label' => 'Zoom', 'asks_invitee' => false])
            ->assertJsonPath('data.0.location_options.1', ['kind' => 'outbound_call', 'label' => 'Phone call (we call you)', 'asks_invitee' => true])
            ->assertJsonPath('data.0.location_options.2', ['kind' => 'physical', 'label' => 'In person: 1 Main St', 'asks_invitee' => false]);
    }

    /** With several places offered, the one picked is booked - with what it needs. */
    #[Test]
    #[DataProvider('locationChoices')]
    public function the_location_picked_from_the_event_types_options_is_booked(string $kind, ?string $given, array $sent): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [
                    ['kind' => 'zoom_conference'],
                    ['kind' => 'outbound_call'],
                    ['kind' => 'physical', 'location' => '1 Main St'],
                    ['kind' => 'inbound_call', 'phone_number' => '+1 312 555 0199'],
                    ['kind' => 'ask_invitee'],
                    ['kind' => 'custom', 'location' => 'Our office lobby'],
                ]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(array_filter(['location_kind' => $kind, 'location' => $given])))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees" && $request['location'] === $sent);
    }

    public static function locationChoices(): array
    {
        return [
            'zoom' => ['zoom_conference', null, ['kind' => 'zoom_conference']],
            'we call them' => ['outbound_call', '+1 312 555 0100', ['kind' => 'outbound_call', 'location' => '+1 312 555 0100']],
            'in person' => ['physical', null, ['kind' => 'physical', 'location' => '1 Main St']],
            'they call us' => ['inbound_call', null, ['kind' => 'inbound_call', 'location' => '+1 312 555 0199']],
            'they choose' => ['ask_invitee', 'Coffee shop on 5th', ['kind' => 'ask_invitee', 'location' => 'Coffee shop on 5th']],
            'custom' => ['custom', null, ['kind' => 'custom', 'location' => 'Our office lobby']],
        ];
    }

    #[Test]
    public function a_location_the_event_type_does_not_offer_is_refused_before_calendly_is_asked(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['location_kind' => 'physical']))
            ->assertStatus(422)->assertJsonValidationErrors('location_kind');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function a_phone_call_event_type_is_booked_with_the_invitees_phone_number(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['location' => '+1 312 555 0100']))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['location'] === ['kind' => 'outbound_call', 'location' => '+1 312 555 0100']);
    }

    /** Nothing typed: the invitee's phone from their FCH profile is the number Calendly calls. */
    #[Test]
    public function a_phone_call_event_type_uses_the_invitees_phone_from_fch(): void
    {
        Borrower::whereKey($this->borrowerUser->entity_id)->update(['phone_number' => '3125550199', 'country_code' => '+1']);
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['location'] === ['kind' => 'outbound_call', 'location' => '+13125550199']);
    }

    #[Test]
    public function a_phone_call_event_type_without_any_phone_number_is_refused_before_calendly_is_asked(): void
    {
        Borrower::whereKey($this->borrowerUser->entity_id)->update(['phone_number' => null]);
        $this->borrowerUser->update(['phone_number' => null]);
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location' => __('calendly.phone_missing', ['name' => $this->borrowerUser->name])]);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function an_event_type_that_is_not_the_companys_is_refused(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['event_type_uri' => "{$this->calendlyApi}/event_types/NOT-OURS"]))
            ->assertStatus(422);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    #[Test]
    public function booking_when_calendly_is_not_connected_says_so(): void
    {
        Http::fake();
        $this->actingAsUser($this->host);

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
        $this->actingAsUser($this->host);

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
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected time is no longer available.');
    }

    /**
     * Defect: Calendly's 400 says only "The supplied parameters are invalid."
     * in `message`; the reason is in `details` (e.g. the phone number). The
     * user saw the vague message and could not tell what to fix.
     */
    #[Test]
    public function calendlys_reason_for_a_refused_field_is_shown_not_its_generic_message(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/invitees" => Http::response([
                'title' => 'Invalid Argument',
                'message' => 'The supplied parameters are invalid.',
                'details' => [['parameter' => 'invitee.email', 'message' => 'invitee.email is not a valid email address']],
            ], 400),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'invitee.email is not a valid email address');
    }

    /**
     * Defect: a phone-call booking with "ddsd" as the number went to Calendly,
     * which refused it with only "The supplied parameters are invalid.".
     */
    #[Test]
    public function a_phone_call_booking_with_something_that_is_not_a_phone_number_is_refused_before_calendly_is_asked(): void
    {
        $this->connectCalendly($this->host);
        $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [
                $this->calendlyEventType(['locations' => [['kind' => 'outbound_call']]]),
            ], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['location' => 'ddsd']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invitees'));
    }

    /**
     * No duplicates: a meeting booked from FCH comes back from Calendly twice
     * more - its webhook and the next sync - and is still one appointment.
     */
    #[Test]
    public function a_meeting_booked_from_fch_and_then_seen_by_the_webhook_and_the_sync_is_one_appointment(): void
    {
        $connection = $this->connectCalendly($this->host);
        [$event, $invitee] = $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/scheduled_events/EVBOOK/invitees*" => Http::response(['collection' => [$this->calendlyInvitee($this->calendlyEvent('EVBOOK', $this->slot), $this->borrowerUser->email, 'Jane Borrower')]]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [$this->calendlyEvent('EVBOOK', $this->slot)], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk()->json('data.id');

        // Calendly's webhook for the same booking.
        $body = json_encode($this->calendlyWebhook('invitee.created', $event, $invitee));
        $this->call('POST', "/api/v1/calendly/webhook/{$connection->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CALENDLY_WEBHOOK_SIGNATURE' => $this->calendlySignature($body),
        ], $body)->assertOk();
        // And the next sync.
        $this->postJson('/api/v1/common/calendly/sync')->assertOk();

        $this->assertSame(1, Appointment::withTrashed()->where('calendly_event_uri', $event['uri'])->count());
        $this->assertSame($id, Appointment::sole()->id);
        $this->assertSame('Funding review', Appointment::sole()->subject);
    }

    /** No duplicates: a reschedule in Calendly leaves one appointment on the calendar, at the new time. */
    #[Test]
    public function a_meeting_rescheduled_in_calendly_is_one_appointment_at_the_new_time(): void
    {
        $connection = $this->connectCalendly($this->host);
        [$old, $oldInvitee] = $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower');
        $this->actingAsUser($this->host);
        $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk();
        $newStart = $this->slot->copy()->addDay();
        $new = $this->calendlyEvent('EVNEW', $newStart);
        $deliver = function (array $payload) use ($connection) {
            $body = json_encode($payload);
            $this->call('POST', "/api/v1/calendly/webhook/{$connection->id}", [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CALENDLY_WEBHOOK_SIGNATURE' => $this->calendlySignature($body),
            ], $body)->assertOk();
        };

        // Calendly reports a reschedule as the old booking cancelled and a new one made.
        $deliver($this->calendlyWebhook('invitee.canceled', array_merge($old, ['status' => 'canceled']), array_merge($oldInvitee, ['status' => 'canceled', 'rescheduled' => true])));
        $deliver($this->calendlyWebhook('invitee.created', $new, $this->calendlyInvitee($new, $this->borrowerUser->email, 'Jane Borrower', ['old_invitee' => $oldInvitee['uri']])));

        $visible = Appointment::sole();
        $this->assertSame($new['uri'], $visible->calendly_event_uri);
        $this->assertSame($newStart->format('Y-m-d H:i:s'), $visible->appointment_date);
        $this->assertSame('Funding review', $visible->subject);
    }

    #[Test]
    public function cancelling_a_calendly_meeting_in_fch_cancels_it_in_calendly(): void
    {
        $this->connectCalendly($this->host);
        [$event] = $this->fakeBooking($this->borrowerUser->email, 'Jane Borrower', [
            "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation" => Http::response(['resource' => ['canceled_by' => 'Host']], 201),
        ]);
        $this->actingAsUser($this->host);
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
        $this->actingAsUser($this->host);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->json('data.id');

        $this->postJson("/api/v1/common/appointments/{$id}/cancel")->assertStatus(502);

        $this->assertSame(Appointment::STATUS_CONFIRMED, Appointment::find($id)->status);
    }

    /**
     * A booked Calendly meeting plus the Calendly answers for changing it in
     * FCH: the old meeting and its invitee, the open times of $newSlot's day,
     * its cancellation, and the new meeting (EVNEW) booked in its place.
     *
     * @return array{int, array<string, mixed>} the appointment id and the edit form
     */
    private function bookedMeetingToChange(Carbon $newSlot, array $overrides = []): array
    {
        $this->connectCalendly($this->host);
        $old = $this->calendlyEvent('EVBOOK', $this->slot);
        $oldInvitee = $this->calendlyInvitee($old, $this->borrowerUser->email, 'Jane Borrower');
        $new = $this->calendlyEvent('EVNEW', $newSlot);
        $newInvitee = $this->calendlyInvitee($new, $this->borrowerUser->email, 'Jane Borrower');

        Http::fake($overrides + [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/event_type_available_times?*" => Http::response(['collection' => [
                ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
                ['status' => 'available', 'start_time' => $newSlot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
            ]]),
            "{$this->calendlyApi}/one_off_event_types" => Http::response(['title' => 'Permission Denied'], 403),
            "{$this->calendlyApi}/invitees" => Http::sequence()
                ->push(['resource' => $oldInvitee], 201)
                ->push(['resource' => $newInvitee], 201),
            "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation" => Http::response(['resource' => ['canceled_by' => 'FCH']], 201),
            "{$oldInvitee['uri']}" => Http::response(['resource' => $oldInvitee]),
            "{$this->calendlyApi}/scheduled_events/EVBOOK" => Http::response(['resource' => $old]),
            "{$this->calendlyApi}/scheduled_events/EVNEW" => Http::response(['resource' => $new]),
        ]);
        $this->actingAsUser($this->host);
        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload(['assigned_for' => $this->borrowerUser->id]))->assertOk()->json('data.id');

        // The edit form sends the whole meeting back, as the portals do.
        return [$id, [
            'subject' => 'Funding review',
            'appointment_date' => $this->slot->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
            'assigned_for' => $this->borrowerUser->id,
        ]];
    }

    /** A new time in FCH: the meeting is replaced in Calendly - the old one cancelled, a new one at the new time. */
    #[Test]
    public function changing_a_calendly_meetings_time_in_fch_moves_it_in_calendly(): void
    {
        $newSlot = $this->slot->copy()->addHours(2);
        [$id, $form] = $this->bookedMeetingToChange($newSlot);

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['appointment_date' => $newSlot->format('Y-m-d H:i:s')]))
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation");
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['start_time'] === $newSlot->format('Y-m-d\TH:i:s\Z')
            && $request['invitee']['email'] === $this->borrowerUser->email);
        // The same appointment, now the new meeting - no duplicate.
        $this->assertSame(1, Appointment::count());
        $appointment = Appointment::find($id);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVNEW", $appointment->calendly_event_uri);
        $this->assertSame($newSlot->format('Y-m-d H:i:s'), $appointment->appointment_date);
        $this->assertSame('Funding review', $appointment->subject);
    }

    /** A new title in FCH: the meeting is replaced in Calendly, named with it (a one-off meeting), same time. */
    #[Test]
    public function changing_a_calendly_meetings_title_in_fch_renames_it_in_calendly(): void
    {
        [$id, $form] = $this->bookedMeetingToChange($this->slot, [
            "{$this->calendlyApi}/one_off_event_types" => Http::sequence()
                ->push(['title' => 'Permission Denied'], 403)
                ->push(['resource' => ['uri' => "{$this->calendlyApi}/event_types/ONEOFF2"]], 201),
        ]);

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['subject' => 'Renamed review']))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation");
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/one_off_event_types"
            && $request['name'] === 'Renamed review');
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === "{$this->calendlyApi}/event_types/ONEOFF2"
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(1, Appointment::count());
        $this->assertSame('Renamed review', Appointment::find($id)->subject);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVNEW", Appointment::find($id)->calendly_event_uri);
    }

    #[Test]
    public function a_new_time_not_open_in_calendly_is_refused_and_nothing_is_cancelled(): void
    {
        [$id, $form] = $this->bookedMeetingToChange($this->slot->copy()->addHours(2));

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['appointment_date' => $this->slot->copy()->addHours(5)->format('Y-m-d H:i:s')]))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_date');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/cancellation'));
        $this->assertSame($this->slot->format('Y-m-d H:i:s'), Appointment::find($id)->appointment_date);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVBOOK", Appointment::find($id)->calendly_event_uri);
    }

    /** The new meeting refused after the old one was cancelled: the old one is booked again, the change refused. */
    #[Test]
    public function a_failed_replacement_books_the_old_meeting_again(): void
    {
        $newSlot = $this->slot->copy()->addHours(2);
        $restored = $this->calendlyEvent('EVBACK', $this->slot);
        $restoredInvitee = $this->calendlyInvitee($restored, $this->borrowerUser->email, 'Jane Borrower');
        $old = $this->calendlyEvent('EVBOOK', $this->slot);
        [$id, $form] = $this->bookedMeetingToChange($newSlot, [
            "{$this->calendlyApi}/invitees" => Http::sequence()
                ->push(['resource' => $this->calendlyInvitee($old, $this->borrowerUser->email, 'Jane Borrower')], 201)
                ->push(['title' => 'Invalid Argument', 'message' => 'The start time is no longer available.'], 400)
                ->push(['resource' => $restoredInvitee], 201),
            "{$this->calendlyApi}/scheduled_events/EVBACK" => Http::response(['resource' => $restored]),
        ]);

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['appointment_date' => $newSlot->format('Y-m-d H:i:s')]))
            ->assertStatus(422);

        $appointment = Appointment::find($id);
        // Still one appointment, at the old time, on the meeting booked again.
        $this->assertSame(1, Appointment::count());
        $this->assertSame($this->slot->format('Y-m-d H:i:s'), $appointment->appointment_date);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVBACK", $appointment->calendly_event_uri);
    }

    /** Only the description or type changed: nothing to do in Calendly. */
    #[Test]
    public function changing_other_fields_of_a_calendly_meeting_does_not_touch_calendly(): void
    {
        [$id, $form] = $this->bookedMeetingToChange($this->slot->copy()->addHours(2));

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['description' => 'Bring the statements']))->assertOk();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/cancellation'));
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVBOOK", Appointment::find($id)->calendly_event_uri);
    }

    /** The old meeting's cancellation (webhook) while FCH replaces it does not take the appointment off. */
    #[Test]
    public function the_replaced_meetings_cancellation_does_not_remove_the_appointment(): void
    {
        $newSlot = $this->slot->copy()->addHours(2);
        [$id, $form] = $this->bookedMeetingToChange($newSlot);
        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['appointment_date' => $newSlot->format('Y-m-d H:i:s')]))->assertOk();

        $old = $this->calendlyEvent('EVBOOK', $this->slot, ['status' => 'canceled']);
        $body = json_encode($this->calendlyWebhook('invitee.canceled', $old, array_merge($this->calendlyInvitee($old, $this->borrowerUser->email, 'Jane Borrower'), ['status' => 'canceled'])));
        $connection = \App\Models\Calendly\CalendlyConnection::firstOrFail();
        $this->call('POST', "/api/v1/calendly/webhook/{$connection->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CALENDLY_WEBHOOK_SIGNATURE' => $this->calendlySignature($body),
        ], $body)->assertOk();

        $this->assertNotNull(Appointment::find($id));
        $this->assertSame(Appointment::STATUS_CONFIRMED, Appointment::find($id)->status);
    }

    /**
     * The connection signed in again with another Calendly account: its old
     * meetings are not readable (403). Syncing skips them, deleting one
     * deletes it in FCH, editing one saves in FCH - none of it fails.
     */
    #[Test]
    public function meetings_of_a_previous_calendly_account_neither_break_sync_nor_block_delete_or_edit(): void
    {
        $connection = $this->connectCalendly($this->host);
        // A meeting brought in while this connection was signed in to another Calendly account.
        $old = "{$this->calendlyApi}/scheduled_events/EVOLD";
        $appointment = Appointment::create([
            'appointment_code' => 'APP-0900', 'source' => Appointment::SOURCE_CALENDLY, 'subject' => 'Old account meeting',
            'appointment_date' => $this->slot->format('Y-m-d H:i:s'), 'timezone' => 'UTC', 'duration_minutes' => 30,
            'status' => Appointment::STATUS_CONFIRMED, 'scheduled_by' => $this->host->id, 'assigned_for' => $this->borrowerUser->id,
            'calendly_event_uri' => $old, 'calendly_connection_id' => $connection->id,
        ]);
        $id = $appointment->id;
        $denied = Http::response(['title' => 'Permission Denied', 'message' => 'You are not allowed to view this event'], 403);
        Http::fake([
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
            "{$old}/cancellation" => $denied,
            $old => $denied,
        ]);
        $this->actingAsUser($this->host);
        $form = [
            'subject' => 'Old account meeting', 'appointment_date' => $this->slot->format('Y-m-d H:i:s'), 'timezone' => 'UTC',
            'duration_minutes' => 30, 'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW, 'assigned_for' => $this->borrowerUser->id,
        ];

        $this->postJson('/api/v1/common/calendly/sync')->assertOk();
        $this->assertSame(Appointment::STATUS_CONFIRMED, Appointment::find($id)->status);

        $this->putJson("/api/v1/common/appointments/{$id}", array_merge($form, ['subject' => 'Renamed in FCH']))->assertOk();
        $this->assertSame('Renamed in FCH', Appointment::find($id)->subject);

        $this->deleteJson("/api/v1/common/appointments/{$id}")->assertOk();
        $this->assertNull(Appointment::find($id));
    }

    /** calendly:retitle --dry-run: lists a meeting whose Calendly name is not its FCH title, changes nothing. */
    #[Test]
    public function retitle_dry_run_lists_meetings_whose_calendly_name_differs_and_changes_nothing(): void
    {
        [$id] = $this->bookedMeetingToChange($this->slot->copy()->addHours(2));

        $this->artisan('calendly:retitle', ['--dry-run' => true])
            ->expectsOutputToContain('Upcoming Calendly meetings to give their FCH title: 1')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/cancellation'));
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVBOOK", Appointment::find($id)->calendly_event_uri);
    }

    /** calendly:retitle: the meeting is booked again under its FCH title, same time; the old one cancelled. */
    #[Test]
    public function retitle_books_an_old_meeting_again_under_its_fch_title(): void
    {
        $renamed = $this->calendlyEvent('EVNEW', $this->slot, ['name' => 'Funding review']);
        [$id] = $this->bookedMeetingToChange($this->slot, [
            "{$this->calendlyApi}/one_off_event_types" => Http::sequence()
                ->push(['title' => 'Permission Denied'], 403)
                ->push(['resource' => ['uri' => "{$this->calendlyApi}/event_types/ONEOFF3"]], 201),
            "{$this->calendlyApi}/scheduled_events/EVNEW" => Http::response(['resource' => $renamed]),
        ]);

        $this->artisan('calendly:retitle', ['--force' => true])
            ->expectsOutputToContain('Calendly name now "Funding review" - YES, same title')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/scheduled_events/EVBOOK/cancellation");
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/one_off_event_types" && $request['name'] === 'Funding review');
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request['event_type'] === "{$this->calendlyApi}/event_types/ONEOFF3"
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z'));
        $appointment = Appointment::find($id);
        $this->assertSame(1, Appointment::count());
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVNEW", $appointment->calendly_event_uri);
        $this->assertSame($this->slot->format('Y-m-d H:i:s'), $appointment->appointment_date);
        $this->assertSame('Funding review', $appointment->subject);
    }

    /**
     * Calendly lists a meeting only in its host's account: booked with someone
     * who has their own Calendly connected, it is booked there too (the booker
     * its invitee) - one appointment, shown in both people's Calendly. Their
     * sync does not take the copy in as a second appointment; cancelling the
     * appointment cancels both.
     */
    #[Test]
    public function a_meeting_with_someone_with_their_own_calendly_is_booked_in_theirs_too(): void
    {
        $this->connectCalendly($this->host);
        $borrowerUri = "{$this->calendlyApi}/users/BORROWERUSER";
        $borrowersOwn = $this->connectCalendly($this->borrowerUser, [
            'borrower_id' => $this->borrowerUser->entity_id,
            'calendly_user_uri' => $borrowerUri,
            'access_token' => 'borrower-access-token',
        ]);

        $event = $this->calendlyEvent('EVBOOK', $this->slot);
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane Borrower');
        $copy = $this->calendlyEvent('EVMIRROR', $this->slot, [
            'event_type' => "{$this->calendlyApi}/event_types/BET1",
            'event_memberships' => [['user' => $borrowerUri, 'user_email' => 'borrower@example.test']],
        ]);
        $copyInvitee = $this->calendlyInvitee($copy, $this->host->email, $this->host->name);
        $isBorrowers = fn (Request $request) => $request->hasHeader('Authorization', 'Bearer borrower-access-token');

        Http::fake(function (Request $request) use ($event, $invitee, $copy, $copyInvitee, $isBorrowers) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);

            return match (true) {
                $path === '/event_types' => Http::response(['collection' => [$isBorrowers($request)
                    ? $this->calendlyEventType(['uri' => "{$this->calendlyApi}/event_types/BET1"])
                    : $this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
                $path === '/event_type_available_times' => Http::response(['collection' => [
                    ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
                ]]),
                $path === '/user_busy_times' => Http::response(['collection' => []]),
                $path === '/one_off_event_types' => Http::response(['title' => 'Permission Denied', 'message' => 'Insufficient scope'], 403),
                $path === '/invitees' => Http::response(['resource' => $isBorrowers($request) ? $copyInvitee : $invitee], 201),
                $path === '/scheduled_events' => Http::response(['collection' => [$copy], 'pagination' => ['next_page_token' => null]]),
                $path === '/scheduled_events/EVBOOK' => Http::response(['resource' => $event]),
                $path === '/scheduled_events/EVMIRROR' => Http::response(['resource' => $copy]),
                $path === '/scheduled_events/EVMIRROR/invitees' => Http::response(['collection' => [$copyInvitee]]),
                str_ends_with($path, '/cancellation') => Http::response(['resource' => ['canceled_by' => 'FCH']], 201),
                default => Http::response(['title' => 'Not faked', 'message' => $url], 404),
            };
        });
        $this->actingAsUser($this->host);

        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk()->json('data.id');

        // Booked in the borrower's own Calendly, with their sign-in, the host the invitee.
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $isBorrowers($request)
            && $request['event_type'] === "{$this->calendlyApi}/event_types/BET1"
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z')
            && $request['invitee']['email'] === $this->host->email);

        $appointment = Appointment::findOrFail($id);
        $this->assertSame($event['uri'], $appointment->calendly_event_uri);
        $this->assertSame($copy['uri'], $appointment->calendly_mirror_event_uri);
        $this->assertSame($copyInvitee['uri'], $appointment->calendly_mirror_invitee_uri);
        $this->assertSame($borrowersOwn->id, (int) $appointment->calendly_mirror_connection_id);

        // The borrower's sync sees the copy: still one appointment.
        app(\App\Services\Global\Calendly\CalendlySyncService::class)->syncRange($borrowersOwn, now()->subDay(), now()->addDays(10));
        $this->assertSame(1, Appointment::count());

        $this->postJson("/api/v1/common/appointments/{$id}/cancel")->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === "{$event['uri']}/cancellation");
        Http::assertSent(fn (Request $request) => $request->url() === "{$copy['uri']}/cancellation" && $isBorrowers($request));
    }

    /** Their Calendly refusing the copy does not undo the meeting: it is booked, only without the copy. */
    #[Test]
    public function the_meeting_stands_when_the_other_persons_calendly_refuses_the_copy(): void
    {
        $this->connectCalendly($this->host);
        $this->connectCalendly($this->borrowerUser, [
            'borrower_id' => $this->borrowerUser->entity_id,
            'calendly_user_uri' => "{$this->calendlyApi}/users/BORROWERUSER",
            'access_token' => 'borrower-access-token',
        ]);
        $event = $this->calendlyEvent('EVBOOK', $this->slot);
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane Borrower');

        Http::fake(function (Request $request) use ($event, $invitee) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $borrowers = $request->hasHeader('Authorization', 'Bearer borrower-access-token');

            return match (true) {
                $path === '/event_types' => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
                $path === '/event_type_available_times' => Http::response(['collection' => [
                    ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
                ]]),
                $path === '/user_busy_times' => Http::response(['collection' => []]),
                $path === '/one_off_event_types' => Http::response(['title' => 'Permission Denied', 'message' => 'Insufficient scope'], 403),
                // The Free plan's daily limit reached in the borrower's Calendly.
                $path === '/invitees' => $borrowers
                    ? Http::response(['title' => 'Too Many Requests', 'message' => 'Limit reached'], 429)
                    : Http::response(['resource' => $invitee], 201),
                $path === '/scheduled_events/EVBOOK' => Http::response(['resource' => $event]),
                default => Http::response(['title' => 'Not faked'], 404),
            };
        });
        $this->actingAsUser($this->host);

        $id = $this->postJson('/api/v1/common/calendly/book', $this->bookingPayload())->assertOk()->json('data.id');

        $appointment = Appointment::findOrFail($id);
        $this->assertSame($event['uri'], $appointment->calendly_event_uri);
        $this->assertNull($appointment->calendly_mirror_event_uri);
    }

    /** An FCH-only meeting at $this->slot, set by $by with $with. */
    private function fchMeeting(User $by, User $with, array $overrides = []): Appointment
    {
        return Appointment::create(array_merge([
            'appointment_code' => 'APP-'.random_int(1000, 9999),
            'subject' => 'Quarterly review',
            'description' => 'Numbers',
            'appointment_date' => $this->slot->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_by' => $by->id,
            'assigned_for' => $with->id,
        ], $overrides));
    }

    /**
     * Calendly for the host (access-token-1) and the borrower's own
     * (borrower-access-token): a booking with either makes a meeting in that
     * account - EVHOST or EVBORROWER - for whoever was invited.
     */
    private function fakeTwoCalendlys(?int $refuseWith = null): void
    {
        $borrowerUri = "{$this->calendlyApi}/users/BORROWERUSER";
        // Each booking is a meeting of its own: the first in each account EVHOST / EVBORROWER, then EVHOST2...
        $events = [];
        $made = ['EVHOST' => 0, 'EVBORROWER' => 0];

        Http::fake(function (Request $request) use (&$events, &$made, $borrowerUri, $refuseWith) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $borrowers = $request->hasHeader('Authorization', 'Bearer borrower-access-token');

            if ($path === '/invitees' && ! $refuseWith) {
                $prefix = $borrowers ? 'EVBORROWER' : 'EVHOST';
                $id = $prefix.(++$made[$prefix] > 1 ? $made[$prefix] : '');
                $events[$id] = $this->calendlyEvent($id, Carbon::parse($request['start_time']), $borrowers ? [
                    'event_type' => "{$this->calendlyApi}/event_types/BET1",
                    'event_memberships' => [['user' => $borrowerUri, 'user_email' => 'borrower@example.test']],
                ] : []);

                return Http::response(['resource' => $this->calendlyInvitee($events[$id], $request['invitee']['email'], $request['invitee']['name'])], 201);
            }

            $eventId = str_starts_with($path, '/scheduled_events/') ? substr($path, strlen('/scheduled_events/')) : null;

            return match (true) {
                $path === '/event_types' => Http::response(['collection' => [$borrowers
                    ? $this->calendlyEventType(['uri' => "{$this->calendlyApi}/event_types/BET1"])
                    : $this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
                $path === '/one_off_event_types' => Http::response(['title' => 'Permission Denied', 'message' => 'Insufficient scope'], 403),
                $path === '/invitees' => Http::response(['title' => 'Refused', 'message' => 'Refused'], $refuseWith),
                // Calendly into FCH: nothing booked in Calendly itself in these dates.
                $path === '/scheduled_events' => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
                $eventId !== null && isset($events[$eventId]) => Http::response(['resource' => $events[$eventId]]),
                default => Http::response(['title' => 'Not faked', 'message' => $request->url()], 404),
            };
        });
    }

    private function connectBorrowersOwn(): \App\Models\Calendly\CalendlyConnection
    {
        return $this->connectCalendly($this->borrowerUser, [
            'borrower_id' => $this->borrowerUser->entity_id,
            'calendly_user_uri' => "{$this->calendlyApi}/users/BORROWERUSER",
            'access_token' => 'borrower-access-token',
        ]);
    }

    /** The dates the calendar shows: the slot's week. */
    private function shownDates(): array
    {
        return [
            'from' => $this->slot->copy()->subDays(3)->toDateString(),
            'to' => $this->slot->copy()->addDays(3)->toDateString(),
            'timezone' => 'UTC',
        ];
    }

    /** Push: a meeting the host set in FCH goes into the host's Calendly and the borrower's own. */
    #[Test]
    public function push_puts_the_callers_fch_meetings_in_both_peoples_calendly(): void
    {
        $hosts = $this->connectCalendly($this->host);
        $borrowers = $this->connectBorrowersOwn();
        $meeting = $this->fchMeeting($this->host, $this->borrowerUser);
        $this->fakeTwoCalendlys();
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'push'] + $this->shownDates())
            ->assertOk()
            ->assertJsonPath('data.pushed', 2)
            ->assertJsonPath('data.pulled', 0)
            ->assertJsonPath('data.failed', 0);

        // In the host's Calendly, the borrower invited; in the borrower's, the host.
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && ! $request->hasHeader('Authorization', 'Bearer borrower-access-token')
            && $request['invitee']['email'] === $this->borrowerUser->email
            && $request['start_time'] === $this->slot->format('Y-m-d\TH:i:s\Z'));
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request->hasHeader('Authorization', 'Bearer borrower-access-token')
            && $request['invitee']['email'] === $this->host->email);
        // Not brought in from Calendly: push only.
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/scheduled_events?"));

        $meeting->refresh();
        $this->assertSame(1, Appointment::count());
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVHOST", $meeting->calendly_event_uri);
        $this->assertSame($hosts->id, (int) $meeting->calendly_connection_id);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVBORROWER", $meeting->calendly_mirror_event_uri);
        $this->assertSame($borrowers->id, (int) $meeting->calendly_mirror_connection_id);
        // FCH's title stays.
        $this->assertSame('Quarterly review', $meeting->subject);

        // Pushed again: already in both - nothing booked twice.
        Http::fake();
        $this->fakeTwoCalendlys();
        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'push'] + $this->shownDates())
            ->assertOk()->assertJsonPath('data.pushed', 0);
        Http::assertNotSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees");
    }

    /** Pull: Calendly's meetings come in, and one the host set with the borrower goes into the borrower's own Calendly. */
    #[Test]
    public function pull_brings_calendly_in_and_puts_meetings_set_with_the_caller_in_their_calendly(): void
    {
        $this->connectCalendly($this->host);
        $borrowers = $this->connectBorrowersOwn();
        $meeting = $this->fchMeeting($this->host, $this->borrowerUser);
        // Set by someone else with the borrower's... and one the borrower set (not pulled).
        $own = $this->fchMeeting($this->borrowerUser, $this->host, ['appointment_date' => $this->slot->copy()->addHour()->format('Y-m-d H:i:s')]);
        $this->fakeTwoCalendlys();
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'pull'] + $this->shownDates())
            ->assertOk()
            ->assertJsonPath('data.pushed', 1);

        // Calendly into FCH, with the borrower's own sign-in, for the dates shown.
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/scheduled_events?")
            && $request->hasHeader('Authorization', 'Bearer borrower-access-token')
            && str_starts_with((string) $request['min_start_time'], $this->slot->copy()->subDays(3)->toDateString()));
        // In the borrower's Calendly, the host invited.
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/invitees"
            && $request->hasHeader('Authorization', 'Bearer borrower-access-token')
            && $request['invitee']['email'] === $this->host->email);

        $this->assertSame($borrowers->id, (int) $meeting->fresh()->calendly_connection_id);
        $this->assertNull($own->fresh()->calendly_event_uri);
    }

    /** Both: pull and push - the borrower's own meeting goes out, the host's comes in. */
    #[Test]
    public function two_way_does_both(): void
    {
        $this->connectCalendly($this->host);
        $this->connectBorrowersOwn();
        $setWithBorrower = $this->fchMeeting($this->host, $this->borrowerUser);
        $setByBorrower = $this->fchMeeting($this->borrowerUser, $this->host, ['appointment_date' => $this->slot->copy()->addHour()->format('Y-m-d H:i:s')]);
        $this->fakeTwoCalendlys();
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'both'] + $this->shownDates())
            ->assertOk()
            ->assertJsonPath('data.failed', 0);

        $this->assertNotNull($setWithBorrower->fresh()->calendly_event_uri);
        // The borrower's own: in their Calendly, and in the host's (the partner's) too.
        $this->assertNotNull($setByBorrower->fresh()->calendly_event_uri);
        $this->assertNotNull($setByBorrower->fresh()->calendly_mirror_event_uri);
    }

    /** No dates given: this month only - a meeting later on is left alone. */
    #[Test]
    public function without_dates_the_current_month_is_synced(): void
    {
        $this->connectCalendly($this->host);
        $later = $this->fchMeeting($this->host, $this->borrowerUser, ['appointment_date' => now()->addMonthsNoOverflow(2)->startOfMonth()->addDays(3)->setTime(15, 0)->format('Y-m-d H:i:s')]);
        $this->fakeTwoCalendlys();
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'push', 'timezone' => 'UTC'])
            ->assertOk()
            ->assertJsonPath('data.from', now()->startOfMonth()->toDateString())
            ->assertJsonPath('data.to', now()->endOfMonth()->toDateString())
            ->assertJsonPath('data.pushed', 0);

        $this->assertNull($later->fresh()->calendly_event_uri);
    }

    /** Calendly's booking limit: the meeting is reported, the rest not tried. */
    #[Test]
    public function the_booking_limit_stops_the_push_and_says_which_meeting(): void
    {
        $this->connectCalendly($this->host);
        $first = $this->fchMeeting($this->host, $this->borrowerUser);
        $this->fchMeeting($this->host, $this->borrowerUser, ['appointment_date' => $this->slot->copy()->addHour()->format('Y-m-d H:i:s')]);
        $this->fakeTwoCalendlys(429);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'push'] + $this->shownDates())
            ->assertOk()
            ->assertJsonPath('data.pushed', 0)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.appointment_id', $first->id);

        // Event types, the named one-off (refused for scope), then the one refused booking - not the second meeting.
        Http::assertSentCount(3);
    }

    /**
     * A meeting whose Calendly copy is in a connection since removed (Calendly
     * connected again as a new one): no one can reach that copy, so push books
     * it in both people's current Calendly instead of leaving one out.
     */
    #[Test]
    public function push_replaces_a_copy_in_a_removed_connection(): void
    {
        $hosts = $this->connectCalendly($this->host);
        $borrowers = $this->connectBorrowersOwn();
        $meeting = $this->fchMeeting($this->host, $this->borrowerUser, [
            'source' => Appointment::SOURCE_CALENDLY,
            'calendly_event_uri' => "{$this->calendlyApi}/scheduled_events/EVOLD",
            'calendly_connection_id' => 999, // removed
        ]);
        $this->fakeTwoCalendlys();
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'push'] + $this->shownDates())
            ->assertOk()
            ->assertJsonPath('data.pushed', 2)
            ->assertJsonPath('data.failed', 0);

        $meeting->refresh();
        $this->assertSame($hosts->id, (int) $meeting->calendly_connection_id);
        $this->assertSame("{$this->calendlyApi}/scheduled_events/EVHOST", $meeting->calendly_event_uri);
        $this->assertSame($borrowers->id, (int) $meeting->calendly_mirror_connection_id);
    }

    #[Test]
    public function an_unknown_direction_or_a_reversed_range_is_refused(): void
    {
        $this->connectCalendly($this->host);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'sideways'])->assertStatus(422)->assertJsonValidationErrors('direction');
        $this->postJson('/api/v1/common/calendly/sync', ['direction' => 'pull', 'from' => '2026-11-10', 'to' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }
}
