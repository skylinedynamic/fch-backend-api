<?php

namespace App\Services\Global\Calendly;

use App\Models\Appointment\Appointment;
use App\Support\Appointment\AppointmentTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A Calendly scheduled event (and its invitee) as the appointment columns it
 * fills. Times are stored in UTC (BUG-080); who the meeting is with - an FCH
 * user or not - is decided by the caller, not here.
 */
final class CalendlyEventMapper
{
    private const CALENDLY_CANCELLED = 'canceled';

    /**
     * @param  array  $event  Calendly scheduled_event resource
     * @param  array|null  $invitee  Calendly invitee resource
     * @param  string  $fallbackZone  the zone used when the invitee's is unknown
     */
    public static function attributes(array $event, ?array $invitee, string $fallbackZone): array
    {
        $start = CarbonImmutable::parse($event['start_time'])->utc();
        $end = CarbonImmutable::parse($event['end_time'])->utc();

        $cancelled = ($event['status'] ?? null) === self::CALENDLY_CANCELLED
            || ($invitee['status'] ?? null) === self::CALENDLY_CANCELLED;

        $location = is_array($event['location'] ?? null) ? $event['location'] : [];
        $joinUrl = $location['join_url'] ?? null;
        $where = $location['location'] ?? $joinUrl ?? null;

        $inviteeName = $invitee['name'] ?? null;
        $subject = trim(($event['name'] ?? 'Calendly meeting').($inviteeName ? " with {$inviteeName}" : ''));

        return [
            'source' => Appointment::SOURCE_CALENDLY,
            'calendly_event_uri' => $event['uri'],
            'calendly_invitee_uri' => $invitee['uri'] ?? null,
            'calendly_event_type_uri' => $event['event_type'] ?? null,
            'subject' => Str::limit($subject, 252),
            'appointment_date' => $start->format(AppointmentTime::STORED_FORMAT),
            'timezone' => AppointmentTime::zone($invitee['timezone'] ?? null) ?? $fallbackZone,
            'duration_minutes' => max(1, (int) $start->diffInMinutes($end)),
            'status' => $cancelled ? Appointment::STATUS_CANCELLED : Appointment::STATUS_CONFIRMED,
            'location' => $where !== null ? Str::limit((string) $where, 252) : null,
            'join_url' => $joinUrl,
            'invitee_name' => $inviteeName,
            'invitee_email' => isset($invitee['email']) ? Str::lower($invitee['email']) : null,
            'cancel_url' => $invitee['cancel_url'] ?? null,
            'reschedule_url' => $invitee['reschedule_url'] ?? null,
        ];
    }
}
