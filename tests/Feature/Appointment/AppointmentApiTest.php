<?php

namespace Tests\Feature\Appointment;

use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Models\Appointment\Appointment;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Appointments: /common/appointments*, used by both portals - a partner's team
 * books meetings with borrowers and each other, a borrower with their partner.
 * An appointment belongs to the two people on it: whoever scheduled it and
 * whoever it is with. That is the rule the list has always applied.
 */
#[CoversClass(AppointmentController::class)]
class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;
    private User $borrowerUser;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->stranger = $this->makePartnerAdmin($this->makeCertifiedPartner());
    }

    private function appointmentBetween(User $scheduledBy, User $with, array $attributes = []): Appointment
    {
        return Appointment::create(array_merge([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'Kick-off',
            'appointment_date' => now()->addDays(3),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $scheduledBy->id,
            'assigned_for' => $with->id,
        ], $attributes));
    }

    private function bookingPayload(User $with, array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Review call',
            'appointment_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'assigned_for' => $with->id,
        ], $overrides);
    }

    #[Test]
    public function the_list_holds_the_meetings_i_scheduled_or_attend(): void
    {
        $mine = $this->appointmentBetween($this->partnerAdmin, $this->borrowerUser);
        $invited = $this->appointmentBetween($this->borrowerUser, $this->partnerAdmin);
        $this->appointmentBetween($this->stranger, $this->makeBorrowerUser($this->partner));
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->postJson('/api/v1/common/appointments/fetch-all')->assertOk()->json('data'))->pluck('id')->sort()->values();

        $this->assertSame([$mine->id, $invited->id], $ids->all());
    }

    #[Test]
    public function a_borrower_books_a_meeting_with_their_partner(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $id = $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->partnerAdmin))->assertSuccessful()->json('data.id');

        $appointment = Appointment::findOrFail($id);
        $this->assertSame($this->borrowerUser->id, $appointment->scheduled_by);
        $this->assertSame($this->partnerAdmin->id, $appointment->assigned_for);
    }

    /**
     * Defect (findings O20): the bookable-people list was scoped, the write was
     * not - assigned_for took any user id on the platform. A borrower or a
     * partner could book a meeting with another partner's users, putting it in
     * their list and sending them a branded email and a bell notification.
     */
    #[Test]
    public function a_booking_with_someone_off_my_bookable_list_is_refused_and_no_one_is_notified(): void
    {
        foreach ([$this->borrowerUser, $this->partnerAdmin] as $caller) {
            $this->actingAsUser($caller);

            $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->stranger))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['assigned_for']);
        }
        $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->makeBorrowerUser($this->makeCertifiedPartner())))
            ->assertStatus(422)->assertJsonValidationErrors(['assigned_for']);

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->stranger->id)->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    /** The entity on a booking is the attendee's: another partner's borrower cannot be tied to it. */
    #[Test]
    public function a_booking_cannot_point_at_someone_elses_entity(): void
    {
        $theirBorrower = $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->borrowerUser, [
            'entity_type' => 'borrower',
            'entity_id' => $theirBorrower->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['entity_id']);

        $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->borrowerUser, [
            'entity_type' => 'borrower',
            'entity_id' => $this->borrowerUser->entity_id,
        ]))->assertSuccessful();
    }

    #[Test]
    public function a_meeting_cannot_be_moved_to_someone_off_my_list(): void
    {
        $appointment = $this->appointmentBetween($this->partnerAdmin, $this->borrowerUser);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/appointments/{$appointment->id}", $this->bookingPayload($this->stranger))
            ->assertStatus(422)->assertJsonValidationErrors(['assigned_for']);

        $this->assertSame($this->borrowerUser->id, $appointment->fresh()->assigned_for);
    }

    /**
     * Not regressions: the partner edits a meeting a borrower booked with it
     * (the attendee is the partner itself, never on its own list), and the
     * host books with another partner - its list offers every partner.
     */
    #[Test]
    public function an_unchanged_attendee_and_the_hosts_partner_meetings_still_work(): void
    {
        $booked = $this->appointmentBetween($this->borrowerUser, $this->partnerAdmin);
        $this->actingAsUser($this->partnerAdmin);
        $this->putJson("/api/v1/common/appointments/{$booked->id}", $this->bookingPayload($this->partnerAdmin, ['subject' => 'Moved']))
            ->assertOk();
        $this->assertSame('Moved', $booked->fresh()->subject);

        $host = Partner::where('is_host', true)->first();
        $this->actingAsUser($this->makePartnerAdmin($host));
        $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->stranger, ['meeting_with' => 1]))
            ->assertSuccessful();
    }

    #[Test]
    public function a_booking_needs_its_subject_time_length_type_and_attendee(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/appointments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'appointment_date', 'timezone', 'duration_minutes', 'meeting_type', 'assigned_for']);
        $this->postJson('/api/v1/common/appointments', $this->bookingPayload($this->borrowerUser, ['meeting_type' => 'Seance']))
            ->assertStatus(422)->assertJsonValidationErrors(['meeting_type']);
    }

    #[Test]
    public function both_people_on_a_meeting_can_open_and_change_it(): void
    {
        $appointment = $this->appointmentBetween($this->partnerAdmin, $this->borrowerUser);

        foreach ([$this->partnerAdmin, $this->borrowerUser] as $participant) {
            $this->actingAsUser($participant);
            $this->getJson("/api/v1/common/appointments/{$appointment->id}")->assertOk()->assertJsonPath('data.id', $appointment->id);
        }

        $this->putJson("/api/v1/common/appointments/{$appointment->id}", $this->bookingPayload($this->partnerAdmin, ['subject' => 'Moved']))
            ->assertOk();
        $this->assertSame('Moved', $appointment->fresh()->subject);
    }

    #[Test]
    public function the_scheduler_cancels_a_meeting(): void
    {
        $appointment = $this->appointmentBetween($this->partnerAdmin, $this->borrowerUser);
        $this->actingAsUser($this->partnerAdmin);

        $this->deleteJson("/api/v1/common/appointments/{$appointment->id}")->assertOk();

        $this->assertNull(Appointment::find($appointment->id));
    }

    /**
     * Defect: show, update and delete looked the appointment up by id alone -
     * and the borrower portal can reach them - so anyone signed in could read,
     * move or cancel any meeting on the platform.
     */
    #[Test]
    public function someone_not_on_a_meeting_cannot_open_move_or_cancel_it(): void
    {
        $appointment = $this->appointmentBetween($this->partnerAdmin, $this->borrowerUser, ['subject' => 'Private']);

        foreach ([$this->stranger, $this->makeBorrowerUser($this->partner)] as $outsider) {
            $this->actingAsUser($outsider);

            $this->getJson("/api/v1/common/appointments/{$appointment->id}")->assertNotFound();
            $this->putJson("/api/v1/common/appointments/{$appointment->id}", $this->bookingPayload($outsider, ['subject' => 'Hijacked']))
                ->assertNotFound();
            $this->deleteJson("/api/v1/common/appointments/{$appointment->id}")->assertNotFound();
        }

        $this->assertSame('Private', $appointment->fresh()->subject);
    }

    #[Test]
    public function availability_and_bookable_people_load(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/appointments/check-availability', [
            'assigned_for' => $this->partnerAdmin->id,
            'appointment_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'duration_minutes' => 30,
            'timezone' => 'UTC',
        ])->assertSuccessful();

        $ids = collect($this->postJson('/api/v1/common/appointments/fetch-selectable-users')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($this->partnerAdmin->id, $ids->all());
        $this->assertNotContains($this->stranger->id, $ids->all());
    }
}
