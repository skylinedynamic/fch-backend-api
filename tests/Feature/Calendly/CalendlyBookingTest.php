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
    private function fakeBooking(string $email, string $name, array $extra = []): array
    {
        $event = $this->calendlyEvent('EVBOOK', $this->slot);
        $invitee = $this->calendlyInvitee($event, $email, $name);

        Http::fake($extra + [
            "{$this->calendlyApi}/event_types?*" => Http::response(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            // The picked time is open in Calendly.
            "{$this->calendlyApi}/event_type_available_times?*" => Http::response(['collection' => [
                ['status' => 'available', 'start_time' => $this->slot->format('Y-m-d\TH:i:s\Z'), 'invitees_remaining' => 1],
            ]]),
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
