<?php

namespace App\Support\Appointment;

use Carbon\Carbon;
use DateTimeZone;

/**
 * An appointment's time: entered in the booker's zone (their browser), stored
 * in UTC, and read back in the viewer's zone through toTimezone() - which uses
 * the X-Timezone header the apps send (BUG-080).
 */
final class AppointmentTime
{
    public const STORED_FORMAT = 'Y-m-d H:i:s';

    /**
     * The zone's identifier, from the identifier itself ("Asia/Dhaka") or the
     * label the booking form used to send ("Asia/Kolkata (GMT+05:30)");
     * null when it names no known zone.
     */
    public static function zone(?string $value): ?string
    {
        $name = trim((string) preg_replace('/\s*\(.*\)\s*$/', '', (string) $value));

        if ($name === '') {
            return null;
        }

        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : null;
    }

    /** A wall-clock time in $zone, as the UTC value stored. */
    public static function toUtc(string $localTime, string $zone): string
    {
        return toTimezone(Carbon::parse($localTime, $zone), 'UTC', self::STORED_FORMAT);
    }

    /** A stored UTC time, in $zone (the viewer's, from X-Timezone, when null). */
    public static function forViewer($storedUtc, ?string $zone = null, string $format = self::STORED_FORMAT): ?string
    {
        if (empty($storedUtc)) {
            return null;
        }

        return toTimezone(Carbon::parse($storedUtc, 'UTC'), $zone ?? self::viewerZone(), $format);
    }

    /**
     * A stored UTC time in the zone the appointment was booked in - for what
     * has no viewer, such as its emails and activity log lines.
     */
    public static function inBookedZone($storedUtc, ?string $bookedZone): Carbon
    {
        return Carbon::parse($storedUtc, 'UTC')->setTimezone(self::zone($bookedZone) ?? 'UTC');
    }

    /** The viewer's zone: X-Timezone when it names a known zone, else the app's. */
    public static function viewerZone(): string
    {
        return self::zone(request()->header('x-timezone')) ?? config('app.timezone', 'UTC');
    }
}
