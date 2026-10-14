<?php

namespace Tests\Feature\Appointment;

use App\Models\Appointment\Appointment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only the person who scheduled an appointment and the person it is with may
 * open, change or delete it - the same rule the appointment list applies.
 */
class AppointmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => Str::slug($name) . '-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function appointment(User $scheduler, User $attendee): Appointment
    {
        $id = DB::table('appointments')->insertGetId([
            'appointment_code' => 'APT-' . strtoupper(Str::random(6)),
            'scheduled_by' => $scheduler->id,
            'assigned_for' => $attendee->id,
            'subject' => 'Loan review call',
            'appointment_date' => now()->addDay()->setTime(9, 0),
            'duration_minutes' => 30,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Appointment::findOrFail($id);
    }

    #[Test]
    public function someone_outside_the_appointment_cannot_open_or_delete_it(): void
    {
        $appointment = $this->appointment($this->user('Scheduler'), $this->user('Attendee'));
        Passport::actingAs($this->user('Outsider'), [], 'api');

        $this->getJson("/api/v1/common/appointments/{$appointment->id}")->assertForbidden();
        $this->deleteJson("/api/v1/common/appointments/{$appointment->id}")->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_scheduler_and_the_attendee_can_open_it(): void
    {
        $scheduler = $this->user('Scheduler');
        $attendee = $this->user('Attendee');
        $appointment = $this->appointment($scheduler, $attendee);

        Passport::actingAs($scheduler, [], 'api');
        $this->getJson("/api/v1/common/appointments/{$appointment->id}")->assertOk();

        Passport::actingAs($attendee, [], 'api');
        $this->getJson("/api/v1/common/appointments/{$appointment->id}")->assertOk();
    }
}
