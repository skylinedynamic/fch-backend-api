<?php

use App\Support\Appointment\AppointmentTime;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BUG-080: an appointment's time is now stored in UTC and its zone by name.
 * Until now the column held the wall-clock time in the zone the form named,
 * under a label such as "Asia/Kolkata (GMT+05:30)". This moves those
 * appointments to UTC and keeps the zone by name.
 *
 * Only rows still carrying a label are moved. A row saved by the new code -
 * already UTC, zone by name - is never touched, so the migration is safe to
 * run whenever it runs after the deploy, and safe to run twice. Each time is
 * converted with its zone's own rules on that date (daylight saving
 * included), not the fixed offset written in the old label.
 *
 * appointment_details keeps its date-only copy, re-derived from the UTC time.
 */
return new class extends Migration
{
    /** The old form's label: "Zone (GMT+hh:mm)". */
    private const LABEL = '/\(GMT[+-]\d{2}:\d{2}\)\s*$/';

    public function up(): void
    {
        DB::table('appointments')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (!preg_match(self::LABEL, (string) $row->timezone)) {
                    continue;
                }

                $zone = AppointmentTime::zone($row->timezone) ?? 'UTC';
                $utc = $row->appointment_date ? AppointmentTime::toUtc($row->appointment_date, $zone) : null;

                DB::table('appointments')->where('id', $row->id)->update(['appointment_date' => $utc, 'timezone' => $zone]);
                $this->syncDetailDate($row->id, $utc);
            }
        });
    }

    /** Back to the wall-clock time under the old label - what the old code reads. */
    public function down(): void
    {
        DB::table('appointments')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (preg_match(self::LABEL, (string) $row->timezone)) {
                    continue;
                }

                $zone = AppointmentTime::zone($row->timezone) ?? 'UTC';
                $local = $row->appointment_date ? AppointmentTime::inBookedZone($row->appointment_date, $zone) : null;
                $label = $zone . ' (GMT' . ($local ?? Carbon::now($zone))->format('P') . ')';

                DB::table('appointments')->where('id', $row->id)->update([
                    'appointment_date' => $local?->format(AppointmentTime::STORED_FORMAT),
                    'timezone' => $label,
                ]);
                $this->syncDetailDate($row->id, $local?->format(AppointmentTime::STORED_FORMAT));
            }
        });
    }

    private function syncDetailDate(int $appointmentId, ?string $dateTime): void
    {
        if ($dateTime) {
            DB::table('appointment_details')->where('appointment_id', $appointmentId)
                ->update(['appointment_date' => Carbon::parse($dateTime)->format('Y-m-d')]);
        }
    }
};
