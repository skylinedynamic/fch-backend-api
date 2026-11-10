<?php

namespace App\Services\Global\Calendly;

use App\Models\Appointment\Appointment;
use App\Models\Appointment\AppointmentDetail;
use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use App\Services\FCH\Common\Appointment\AppointmentService;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The company Calendly account's meetings, mirrored as appointments.
 *
 * One appointment per Calendly meeting (calendly_event_uri), scheduled by the
 * host who connected the account and with the FCH user whose email booked it -
 * so a borrower's or partner's Calendly meeting shows on their calendar as
 * well as the host's. Seen again (a webhook, a sync), the meeting's time,
 * status and links are brought up to date; what FCH set on it - subject,
 * description, meeting type - is kept. A meeting deleted in FCH stays deleted.
 */
class CalendlySyncService
{
    public const EVENT_CREATED = 'invitee.created';

    public const EVENT_CANCELED = 'invitee.canceled';

    public const WEBHOOK_EVENTS = [self::EVENT_CREATED, self::EVENT_CANCELED];

    private const PAGE_SIZE = 100;

    /** What FCH sets on a meeting, carried onto the new one when it is rescheduled in Calendly. */
    private const FCH_FIELDS = ['subject', 'description', 'meeting_type', 'entity_type', 'entity_id'];

    public function __construct(
        private readonly CalendlyClient $client,
        private readonly AppointmentService $appointmentService,
    ) {}

    /**
     * Brings in every meeting of the connected account starting between $from
     * and $to. Returns how many were brought in or brought up to date.
     */
    public function syncRange(CarbonInterface $from, CarbonInterface $to): int
    {
        $connection = $this->client->connection();
        $count = 0;
        $pageToken = null;

        do {
            $page = $this->client->get('scheduled_events', array_filter([
                'user' => $connection->calendly_user_uri,
                'min_start_time' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'max_start_time' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'count' => self::PAGE_SIZE,
                'sort' => 'start_time:asc',
                'page_token' => $pageToken,
            ]));

            foreach ($page['collection'] ?? [] as $event) {
                if ($this->upsert($event, $this->inviteeOf($event))) {
                    $count++;
                }
            }

            $pageToken = $page['pagination']['next_page_token'] ?? null;
        } while ($pageToken);

        $connection->update(['last_synced_at' => now()]);

        return $count;
    }

    /** A webhook delivery (already verified): a meeting booked or cancelled. */
    public function handleWebhook(array $delivery): ?Appointment
    {
        if (! in_array($delivery['event'] ?? null, self::WEBHOOK_EVENTS, true)) {
            return null;
        }

        $invitee = is_array($delivery['payload'] ?? null) ? $delivery['payload'] : [];
        $event = $invitee['scheduled_event'] ?? null;
        unset($invitee['scheduled_event']);

        if (! is_array($event) || empty($event['uri'])) {
            return null;
        }

        if ($delivery['event'] === self::EVENT_CANCELED) {
            $invitee['status'] = 'canceled';
        }

        $appointment = $this->upsert($event, $invitee);

        if ($appointment && $delivery['event'] === self::EVENT_CANCELED && ! empty($invitee['rescheduled'])) {
            $appointment->update(['remarks' => __('calendly.rescheduled_remark')]);
        }

        return $appointment;
    }

    /**
     * The appointment for this Calendly meeting, created or brought up to
     * date; null when the meeting is not the connected account's.
     */
    public function upsert(array $event, ?array $invitee): ?Appointment
    {
        $connection = CalendlyConnection::current();

        if (! $connection || empty($event['uri']) || ! $this->isConnectionsEvent($event, $connection)) {
            return null;
        }

        $attributes = CalendlyEventMapper::attributes($event, $invitee, $connection->timezone ?: 'UTC');
        $existing = Appointment::withTrashed()->where('calendly_event_uri', $event['uri'])->first();

        if ($existing?->trashed()) {
            return $existing;
        }

        $attendee = $this->attendee($attributes['invitee_email'], $connection);

        if ($existing) {
            $updates = Arr::except($attributes, ['subject']);
            if (! $existing->assigned_for && $attendee) {
                $updates += $this->attendeeFields($attendee);
            }
            $existing->update($updates);

            return $existing->fresh();
        }

        try {
            return DB::transaction(function () use ($attributes, $attendee, $connection, $invitee) {
                $appointment = Appointment::create(array_merge(
                    $attributes,
                    $this->carriedFromRescheduled($invitee['old_invitee'] ?? null),
                    [
                        'appointment_code' => $this->appointmentService->generateNextAppointmentCode(),
                        'scheduled_by' => $connection->connected_by,
                    ],
                    $attendee ? $this->attendeeFields($attendee) : [],
                ));

                // The details history is per pair of users (assigned_for is required there).
                if ($appointment->assigned_for) {
                    AppointmentDetail::create([
                        'appointment_id' => $appointment->id,
                        'scheduled_by' => $appointment->scheduled_by,
                        'assigned_for' => $appointment->assigned_for,
                        'appointment_date' => Str::substr($attributes['appointment_date'], 0, 10),
                        'status' => AppointmentDetail::STATUS_CONFIRMED,
                    ]);
                }

                return $appointment->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            // A webhook and the booking request saw the same new meeting at once.
            return Appointment::where('calendly_event_uri', $event['uri'])->first();
        }
    }

    /** The meeting's active invitee (one-on-one events have one), else its first. */
    private function inviteeOf(array $event): ?array
    {
        $invitees = $this->client->get($event['uri'].'/invitees', ['count' => self::PAGE_SIZE])['collection'] ?? [];

        return collect($invitees)->firstWhere('status', 'active') ?? ($invitees[0] ?? null);
    }

    /** The subscription is the account's own, but a meeting it is not on is never taken in. */
    private function isConnectionsEvent(array $event, CalendlyConnection $connection): bool
    {
        $members = collect($event['event_memberships'] ?? [])->pluck('user')->filter();

        return $members->isEmpty() || $members->contains($connection->calendly_user_uri);
    }

    /** The FCH user who booked: matched on email, never the host themselves. */
    private function attendee(?string $email, CalendlyConnection $connection): ?User
    {
        if (! $email) {
            return null;
        }

        return User::whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->whereKeyNot($connection->connected_by)
            ->first();
    }

    private function attendeeFields(User $attendee): array
    {
        $fields = ['assigned_for' => $attendee->id];

        if ($attendee->entity_type && $attendee->entity_id) {
            $fields['entity_type'] = $attendee->entity_type;
            $fields['entity_id'] = $attendee->entity_id;
        }

        return $fields;
    }

    /** What FCH set on the meeting this one was rescheduled from. */
    private function carriedFromRescheduled(?string $oldInviteeUri): array
    {
        if (! $oldInviteeUri) {
            return [];
        }

        $previous = Appointment::where('calendly_invitee_uri', $oldInviteeUri)->first();

        return $previous ? array_filter($previous->only(self::FCH_FIELDS), fn ($value) => $value !== null) : [];
    }
}
