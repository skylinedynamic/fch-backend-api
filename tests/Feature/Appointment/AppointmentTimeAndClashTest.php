<?php

namespace Tests\Feature\Appointment;

use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-078, 079, 080 - when an appointment is, and whether it fits:
 *
 *   078  a time already passed is refused (judged in the booker's zone);
 *   079  a time that overlaps another live appointment of either person on it
 *        is refused - back-to-back is fine;
 *   080  the time is entered in the booker's (browser) zone, stored in UTC, and
 *        read back in the viewer's zone (the X-Timezone header).
 *
 * "Now" is fixed at 2026-10-26 13:00 UTC (19:00 in Dhaka).
 */
#[CoversClass(AppointmentController::class)]
class AppointmentTimeAndClashTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $owner;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-10-26 13:00:00');
        $this->makeHostPartner();
        $partner = $this->makeCertifiedPartner();
        $this->owner = $this->makePartnerAdmin($partner);
        $this->client = $this->makeBorrowerUser($partner, [], ['name' => 'Ada Lovelace']);
        $this->actingAsUser($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(string $localTime, string $zone = 'UTC', array $overrides = [])
    {
        return $this->postJson('/api/v1/common/appointments', array_merge([
            'subject' => 'Review call',
            'appointment_date' => $localTime,
            'timezone' => $zone,
            'duration_minutes' => 30,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'assigned_for' => $this->client->id,
        ], $overrides));
    }

    /** An appointment already on the books, at a UTC time. */
    private function existing(string $utc, User $scheduledBy, User $with, array $attributes = []): Appointment
    {
        return Appointment::create(array_merge([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'Existing',
            'appointment_date' => $utc,
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $scheduledBy->id,
            'assigned_for' => $with->id,
        ], $attributes));
    }

    // ---- BUG-080: entered in the browser's zone, stored in UTC ---------------

    #[Test]
    public function a_time_entered_in_dhaka_is_stored_in_utc_with_its_zone(): void
    {
        $id = $this->book('2026-10-27 15:00:00', 'Asia/Dhaka')->assertSuccessful()->json('data.id');

        $this->assertDatabaseHas('appointments', ['id' => $id, 'appointment_date' => '2026-10-27 09:00:00', 'timezone' => 'Asia/Dhaka']);
    }

    #[Test]
    public function a_zone_sent_as_its_label_is_read_by_its_name(): void
    {
        $id = $this->book('2026-10-27 15:00:00', 'Asia/Kolkata (GMT+05:30)')->assertSuccessful()->json('data.id');

        $this->assertDatabaseHas('appointments', ['id' => $id, 'appointment_date' => '2026-10-27 09:30:00', 'timezone' => 'Asia/Kolkata']);
    }

    #[Test]
    public function an_unknown_zone_is_refused(): void
    {
        $this->book('2026-10-27 15:00:00', 'Mars/Olympus')->assertStatus(422)->assertJsonValidationErrors(['timezone']);
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function each_viewer_reads_the_time_in_their_own_zone(): void
    {
        $appointment = $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        $this->withHeader('X-Timezone', 'Asia/Dhaka')->getJson("/api/v1/common/appointments/{$appointment->id}")
            ->assertOk()->assertJsonPath('data.appointment_date', '2026-10-27 15:00:00');
        $this->withHeader('X-Timezone', 'America/New_York')->getJson("/api/v1/common/appointments/{$appointment->id}")
            ->assertOk()->assertJsonPath('data.appointment_date', '2026-10-27 05:00:00');
    }

    #[Test]
    public function the_list_is_read_in_the_viewers_zone_too(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        $dates = collect($this->withHeader('X-Timezone', 'Asia/Dhaka')->postJson('/api/v1/common/appointments/fetch-all')->assertOk()->json('data'))
            ->pluck('appointment_date')->all();

        $this->assertSame(['2026-10-27 15:00:00'], $dates);
    }

    #[Test]
    public function the_availability_check_finds_a_clash_on_the_viewers_own_day(): void
    {
        // 20:00 UTC on the 27th is 02:00 on the 28th in Dhaka.
        $this->existing('2026-10-27 20:00:00', $this->owner, $this->client);

        $response = $this->withHeader('X-Timezone', 'Asia/Dhaka')->postJson('/api/v1/common/appointments/check-availability', [
            'assigned_for' => $this->client->id,
            'appointment_date' => '2026-10-28 02:00:00',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->assertStringContainsString('"start_time":"02:00"', $response->getContent());
    }

    /**
     * Defect: R2 BUG-073 - the appointment form's live check sent the client's
     * record id as `assigned_for`. That id is not a user (422 "Assigned user
     * does not exist."), or it is someone else's, so the client's clash only
     * showed after Confirm. The form now sends the client's user id, as Save does.
     */
    #[Test]
    public function the_availability_check_finds_the_clients_clash_by_their_user_id(): void
    {
        $colleague = $this->makePartnerAdmin($this->client->partner);
        $this->existing('2026-10-27 09:00:00', $colleague, $this->client);

        $this->postJson('/api/v1/common/appointments/check-availability', [
            'assigned_for' => $this->client->id,
            'appointment_date' => '2026-10-27 09:00:00',
            'timezone' => 'UTC',
        ])
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.assignee_conflicts.0.start_time', '09:00')
            ->assertJsonPath('data.scheduler_conflicts', []);
    }

    /**
     * R2 BUG-073 - a check that names the client by their record instead
     * (entity_type + entity_id, no assigned_for) is resolved to the same user.
     */
    #[Test]
    public function the_availability_check_resolves_a_client_named_by_record_to_their_user(): void
    {
        // Ids that cannot be mistaken for one another.
        $this->makeBorrowerUser($this->client->partner);
        $this->makeBorrowerUser($this->client->partner);
        $client = $this->makeBorrowerUser($this->client->partner, [], ['name' => 'Grace Hopper']);
        $this->assertNotSame($client->id, $client->entity_id);

        $colleague = $this->makePartnerAdmin($this->client->partner);
        $this->existing('2026-10-27 09:00:00', $colleague, $client);

        $this->postJson('/api/v1/common/appointments/check-availability', [
            'entity_type' => 'borrower',
            'entity_id' => $client->entity_id,
            'appointment_date' => '2026-10-27 09:00:00',
            'timezone' => 'UTC',
        ])
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.assignee_conflicts.0.start_time', '09:00');
    }

    #[Test]
    public function the_email_shows_the_time_in_the_zone_it_was_booked_in(): void
    {
        $appointment = $this->existing('2026-10-27 09:00:00', $this->owner, $this->client, ['timezone' => 'Asia/Dhaka']);

        $html = (new \App\Mail\Appointment\AppointmentNotification($appointment, 'created'))->render();

        $this->assertStringContainsString('3:00 PM - 3:30 PM', $html);
        $this->assertStringContainsString('Asia/Dhaka', $html);
    }

    // ---- BUG-078: no time that has already passed ---------------------------

    #[Test]
    public function a_time_earlier_today_is_refused(): void
    {
        $this->book('2026-10-26 09:00:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date' => __('appointment.time_in_past')]);
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function the_past_is_judged_in_the_bookers_zone(): void
    {
        // 18:00 Dhaka is 12:00 UTC - an hour ago; 20:00 Dhaka is 14:00 UTC - an hour away.
        $this->book('2026-10-26 18:00:00', 'Asia/Dhaka')->assertStatus(422)->assertJsonValidationErrors(['appointment_date']);
        $this->book('2026-10-26 20:00:00', 'Asia/Dhaka')->assertSuccessful();
    }

    #[Test]
    public function an_appointment_cannot_be_moved_into_the_past(): void
    {
        $appointment = $this->existing('2026-10-28 09:00:00', $this->owner, $this->client);

        $this->putJson("/api/v1/common/appointments/{$appointment->id}", [
            'appointment_date' => '2026-10-26 09:00:00', 'timezone' => 'UTC', 'assigned_for' => $this->client->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['appointment_date']);
    }

    #[Test]
    public function a_past_appointment_can_still_be_edited_if_its_time_is_kept(): void
    {
        $appointment = $this->existing('2026-10-20 09:00:00', $this->owner, $this->client);

        $this->putJson("/api/v1/common/appointments/{$appointment->id}", [
            'subject' => 'Notes added', 'appointment_date' => '2026-10-20 09:00:00', 'timezone' => 'UTC', 'assigned_for' => $this->client->id,
        ])->assertSuccessful();

        $this->assertSame('Notes added', $appointment->fresh()->subject);
    }

    // ---- BUG-079: no overlapping appointments -------------------------------

    #[Test]
    public function an_overlap_with_the_attendees_appointment_is_refused(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->makePartnerAdmin($this->makeCertifiedPartner()), $this->client);

        $this->book('2026-10-27 09:15:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date' => __('appointment.time_taken_by', ['name' => 'Ada Lovelace', 'from' => '9:00 AM', 'to' => '9:30 AM'])]);
        $this->assertSame(1, Appointment::count());
    }

    #[Test]
    public function the_same_slot_twice_is_refused(): void
    {
        $this->book('2026-10-27 09:00:00')->assertSuccessful();
        $this->book('2026-10-27 09:00:00')->assertStatus(422)->assertJsonValidationErrors(['appointment_date']);

        $this->assertSame(1, Appointment::count());
    }

    /**
     * Defect: R2 BUG-073 - booking the client again into a slot the two of
     * them already share answered "You already have an appointment...",
     * which reads as if only the booker were busy. Both are named.
     */
    #[Test]
    public function a_clash_the_booker_and_the_attendee_share_names_them_both(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        $this->book('2026-10-27 09:00:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date' => __('appointment.time_taken_by_both', ['name' => 'Ada Lovelace', 'from' => '9:00 AM', 'to' => '9:30 AM'])]);
    }

    #[Test]
    public function an_overlap_with_the_bookers_own_appointment_is_refused(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->makeBorrowerUser($this->client->partner));

        $this->book('2026-10-27 09:20:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date' => __('appointment.time_taken_by_you', ['from' => '9:00 AM', 'to' => '9:30 AM'])]);
    }

    #[Test]
    public function back_to_back_is_fine(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        $this->book('2026-10-27 09:30:00')->assertSuccessful();
        $this->book('2026-10-27 08:30:00')->assertSuccessful();
    }

    #[Test]
    public function the_clash_is_found_across_zones(): void
    {
        // 09:00 UTC is 15:00 in Dhaka.
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        // The booker and the client share that appointment (R2 BUG-073).
        $this->book('2026-10-27 15:10:00', 'Asia/Dhaka')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date' => __('appointment.time_taken_by_both', ['name' => 'Ada Lovelace', 'from' => '3:00 PM', 'to' => '3:30 PM'])]);
    }

    #[Test]
    public function a_cancelled_appointment_does_not_hold_its_slot(): void
    {
        $this->existing('2026-10-27 09:00:00', $this->owner, $this->client, ['status' => Appointment::STATUS_CANCELLED]);

        $this->book('2026-10-27 09:00:00')->assertSuccessful();
    }

    #[Test]
    public function an_appointment_does_not_clash_with_itself_when_edited(): void
    {
        $appointment = $this->existing('2026-10-27 09:00:00', $this->owner, $this->client);

        $this->putJson("/api/v1/common/appointments/{$appointment->id}", [
            'appointment_date' => '2026-10-27 09:15:00', 'timezone' => 'UTC', 'assigned_for' => $this->client->id,
        ])->assertSuccessful();

        $this->assertSame('2026-10-27 09:15:00', DB::table('appointments')->where('id', $appointment->id)->value('appointment_date'));
    }
}
