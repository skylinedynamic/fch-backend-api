<?php

namespace Tests\Feature\Appointment;

use App\Mail\Appointment\AppointmentNotification;
use App\Models\Appointment\Appointment;
use App\Models\User\User;
use App\Services\FCH\Common\Appointment\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-189: when the host rescheduled an appointment the client had booked, the
 * "Appointment updated" bell and email went to the host - the person who made
 * the change - and the client was never told. An update or a delete now tells
 * the OTHER person on the appointment, whoever booked it (as cancel does).
 */
#[CoversClass(AppointmentService::class)]
class AppointmentChangeNotificationTest extends TestCase
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

    private function booked(User $by, User $with): Appointment
    {
        return Appointment::create([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'QA-TEST Run2 E2E-21 booking',
            'appointment_date' => now()->addDays(3)->setTime(14, 0),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $by->id,
            'assigned_for' => $with->id,
        ]);
    }

    private function reschedule(Appointment $appointment, User $as): void
    {
        $this->actingAsUser($as);
        $this->putJson("/api/v1/common/appointments/{$appointment->id}", [
            'subject' => $appointment->subject,
            'appointment_date' => now()->addDays(3)->setTime(15, 0)->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'meeting_type' => $appointment->meeting_type,
            'assigned_for' => $appointment->assigned_for,
        ])->assertOk();
    }

    private function toldOnly(User $user): void
    {
        Mail::assertQueued(AppointmentNotification::class, 1);
        Mail::assertQueued(AppointmentNotification::class, fn ($mail) => $mail->hasTo($user->email));
    }

    #[Test]
    public function the_host_reschedules_what_the_client_booked_and_the_client_is_told(): void
    {
        $this->reschedule($this->booked($this->client, $this->hostAdmin), $this->hostAdmin);

        $this->toldOnly($this->client);
    }

    #[Test]
    public function the_host_reschedules_what_it_booked_and_the_client_is_told(): void
    {
        $this->reschedule($this->booked($this->hostAdmin, $this->client), $this->hostAdmin);

        $this->toldOnly($this->client);
    }

    #[Test]
    public function the_client_reschedules_what_it_booked_and_the_host_is_told(): void
    {
        $this->reschedule($this->booked($this->client, $this->hostAdmin), $this->client);

        $this->toldOnly($this->hostAdmin);
    }

    #[Test]
    public function the_host_deletes_what_the_client_booked_and_the_client_is_told(): void
    {
        $appointment = $this->booked($this->client, $this->hostAdmin);
        $this->actingAsUser($this->hostAdmin);

        $this->deleteJson("/api/v1/common/appointments/{$appointment->id}")->assertOk();

        $this->toldOnly($this->client);
    }
}
