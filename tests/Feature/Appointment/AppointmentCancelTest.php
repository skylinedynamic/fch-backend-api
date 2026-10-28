<?php

namespace Tests\Feature\Appointment;

use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Mail\Appointment\AppointmentNotification;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Cancelling an appointment someone else booked with you.
 *
 * Defect: R2 BUG-056 - an appointment the host booked with a client opened
 * read-only in the client portal: no way to cancel it. The person it is with
 * (or its organiser) can cancel - not delete - an upcoming appointment, and
 * the other party is emailed once.
 */
#[CoversClass(AppointmentController::class)]
class AppointmentCancelTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->client = $this->makeBorrowerUser($host);
    }

    private function appointment(array $attributes = []): Appointment
    {
        $id = DB::table('appointments')->insertGetId(array_merge([
            'appointment_code' => 'APP-' . strtoupper(Str::random(6)),
            'scheduled_by' => $this->hostAdmin->id,
            'assigned_for' => $this->client->id,
            'subject' => 'R2 CP-APPT-04 admin-created',
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
            'appointment_date' => now()->addDays(2)->setTime(9, 0),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Appointment::findOrFail($id);
    }

    #[Test]
    public function the_client_cancels_an_appointment_the_host_booked_and_the_host_is_told_once(): void
    {
        $appointment = $this->appointment();
        $this->actingAsUser($this->client);

        $this->postJson("/api/v1/common/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);

        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->deleted_at);
        Mail::assertQueued(AppointmentNotification::class, 1);
        Mail::assertQueued(AppointmentNotification::class, fn ($mail) => $mail->hasTo($this->hostAdmin->email));
    }

    #[Test]
    public function the_organiser_may_cancel_too_and_the_client_is_told(): void
    {
        $appointment = $this->appointment();
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/appointments/{$appointment->id}/cancel")->assertOk();

        Mail::assertQueued(AppointmentNotification::class, fn ($mail) => $mail->hasTo($this->client->email));
    }

    #[Test]
    public function someone_outside_the_appointment_cannot_cancel_it(): void
    {
        $appointment = $this->appointment();
        $this->actingAsUser($this->makeBorrowerUser($this->client->partner));

        $this->postJson("/api/v1/common/appointments/{$appointment->id}/cancel")->assertNotFound();
        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);
    }

    #[Test]
    public function a_past_cancelled_or_completed_appointment_cannot_be_cancelled(): void
    {
        $this->actingAsUser($this->client);

        foreach ([
            ['appointment_date' => now()->subHour()],
            ['status' => Appointment::STATUS_CANCELLED],
            ['status' => Appointment::STATUS_COMPLETED],
        ] as $attributes) {
            $appointment = $this->appointment($attributes);
            $this->postJson("/api/v1/common/appointments/{$appointment->id}/cancel")
                ->assertStatus(422)
                ->assertJsonPath('message', __('appointment.cannot_cancel'));
        }

        Mail::assertNothingQueued();
    }
}
