<?php

namespace Tests\Feature\Appointment;

use App\Models\Appointment\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-080: existing appointments held the wall-clock time in the zone their
 * label named; the migration moves them to UTC and keeps the zone by name.
 */
class StoreAppointmentTimesInUtcMigrationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_24_120000_store_appointment_times_in_utc.php');
    }

    private function appointmentAt(string $wallClock, string $zoneLabel): int
    {
        $partner = $this->makeCertifiedPartner();
        $id = DB::table('appointments')->insertGetId([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'Old booking',
            'appointment_date' => $wallClock,
            'timezone' => $zoneLabel,
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $this->makePartnerAdmin($partner)->id,
            'assigned_for' => $this->makeBorrowerUser($partner)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('appointment_details')->insert([
            'appointment_id' => $id, 'scheduled_by' => 1, 'assigned_for' => 1,
            'appointment_date' => substr($wallClock, 0, 10), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function row(int $id): object
    {
        return DB::table('appointments')->where('id', $id)->first(['appointment_date', 'timezone']);
    }

    #[Test]
    public function a_labelled_zone_moves_to_utc_and_keeps_its_name(): void
    {
        $kolkata = $this->appointmentAt('2026-10-27 03:00:00', 'Asia/Kolkata (GMT+05:30)');

        $this->migration()->up();

        $this->assertEquals((object) ['appointment_date' => '2026-10-26 21:30:00', 'timezone' => 'Asia/Kolkata'], $this->row($kolkata));
        // The date-only copy follows the UTC day.
        $this->assertSame('2026-10-26', DB::table('appointment_details')->where('appointment_id', $kolkata)->value('appointment_date'));
    }

    #[Test]
    public function a_utc_appointment_only_has_its_zone_renamed(): void
    {
        $utc = $this->appointmentAt('2026-10-27 09:00:00', 'UTC (GMT+00:00)');

        $this->migration()->up();

        $this->assertEquals((object) ['appointment_date' => '2026-10-27 09:00:00', 'timezone' => 'UTC'], $this->row($utc));
    }

    #[Test]
    public function a_booking_already_saved_in_utc_is_left_alone_whenever_the_migration_runs(): void
    {
        // Booked by the new code before the migration ran: UTC, zone by name.
        $new = $this->appointmentAt('2026-10-27 09:00:00', 'Asia/Dhaka');

        $this->migration()->up();

        $this->assertEquals((object) ['appointment_date' => '2026-10-27 09:00:00', 'timezone' => 'Asia/Dhaka'], $this->row($new));
    }

    #[Test]
    public function running_it_twice_does_not_shift_a_time_twice(): void
    {
        $kolkata = $this->appointmentAt('2026-10-27 03:00:00', 'Asia/Kolkata (GMT+05:30)');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('2026-10-26 21:30:00', $this->row($kolkata)->appointment_date);
    }

    #[Test]
    public function daylight_saving_is_judged_on_the_appointments_own_date(): void
    {
        // The old label says GMT-05:00, but New York is on GMT-04:00 in July.
        $july = $this->appointmentAt('2026-07-15 09:00:00', 'America/New_York (GMT-05:00)');

        $this->migration()->up();

        $this->assertSame('2026-07-15 13:00:00', $this->row($july)->appointment_date);
    }

    #[Test]
    public function down_then_up_again_lands_on_the_same_utc_time(): void
    {
        $chicago = $this->appointmentAt('2026-10-27 09:00:00', 'America/Chicago (GMT-06:00)');

        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->up();

        $this->assertEquals((object) ['appointment_date' => '2026-10-27 14:00:00', 'timezone' => 'America/Chicago'], $this->row($chicago));
    }

    #[Test]
    public function down_puts_the_wall_clock_time_back(): void
    {
        $chicago = $this->appointmentAt('2026-10-27 09:00:00', 'America/Chicago (GMT-06:00)');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame('2026-10-27 09:00:00', $this->row($chicago)->appointment_date);
    }
}
