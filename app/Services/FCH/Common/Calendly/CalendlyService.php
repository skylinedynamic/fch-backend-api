<?php

namespace App\Services\FCH\Common\Calendly;

use App\Http\Middleware\EnsureHostPartner;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Appointment\Appointment;
use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Appointment\AppointmentService;
use App\Services\Global\Calendly\CalendlyClient;
use App\Services\Global\Calendly\CalendlyException;
use App\Services\Global\Calendly\CalendlyOAuthService;
use App\Services\Global\Calendly\CalendlySyncService;
use App\Services\Global\Calendly\CalendlyWebhookSignature;
use App\Support\Appointment\AppointmentTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The company Calendly account as the portals use it: whether it is on, who
 * may manage it, its event types and open slots, and booking into it.
 *
 * A booking is always with the host who connected the account. When the host
 * books, the invitee is the person they pick; when anyone else books, the
 * invitee is them - and only if the host is someone they may book with (the
 * appointment rule, O20).
 */
class CalendlyService
{
    private const EVENT_TYPES_CACHE_SECONDS = 300;

    private const SYNC_DAYS_BACK = 7;

    private const SYNC_DAYS_AHEAD = 60;

    /** Statuses whose appointments hold their slot - as for FCH bookings (BUG-079). */
    private const SLOT_HOLDING_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_RESCHEDULED,
    ];

    public function __construct(
        private readonly CalendlyClient $client,
        private readonly CalendlyOAuthService $oauth,
        private readonly CalendlySyncService $sync,
        private readonly AppointmentService $appointmentService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function status(User $user): array
    {
        $connection = CalendlyConnection::current();
        $canManage = EnsureHostPartner::allows($user);
        $connected = $connection?->isActive() ?? false;

        $status = [
            'connected' => $connected,
            'needs_reconnect' => $connection !== null && ! $connected,
            'can_manage' => $canManage,
            'is_host' => $connection !== null && (int) $connection->connected_by === (int) $user->id,
            'can_book' => $connected && $this->mayBookWithHost($user, $connection),
            'name' => $connection?->name,
            'scheduling_url' => $connection?->scheduling_url,
        ];

        if ($canManage) {
            $status += [
                'email' => $connection?->email,
                'connected_by' => $connection?->connectedBy?->name,
                'webhooks_active' => (bool) $connection?->webhook_subscription_uri,
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            ];
        }

        return $status;
    }

    public function connectUrl(User $user): string
    {
        return $this->oauth->authorizeUrl($user);
    }

    /**
     * The browser coming back from Calendly. Returns where to send it: the
     * portal's appointments page, saying whether it worked.
     */
    public function handleCallback(?string $code, ?string $state, ?string $error): string
    {
        $portal = rtrim((string) config('app.frontend_partner_url'), '/');
        // An address without its scheme (localhost:5174) would be read as a path on the API.
        if (! preg_match('#^https?://#i', $portal)) {
            $portal = 'http://'.$portal;
        }
        $base = $portal.'/appointments?calendly=';
        $user = $this->oauth->consumeState($state);

        if (! $user || $error || ! $code || ! EnsureHostPartner::allows($user)) {
            return $base.'error';
        }

        try {
            $this->oauth->connect($user, $code);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Connecting failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $base.'error';
        }

        try {
            $this->syncNow();
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] First sync failed', ['error' => $e->getMessage()]);
        }

        return $base.'connected';
    }

    public function disconnect(): void
    {
        $connection = CalendlyConnection::current();
        if ($connection) {
            Cache::forget($this->eventTypesCacheKey($connection));
        }

        $this->oauth->disconnect($connection);
    }

    /** Brings in the account's meetings from a week back to two months ahead. */
    public function syncNow(): int
    {
        return $this->sync->syncRange(now()->subDays(self::SYNC_DAYS_BACK), now()->addDays(self::SYNC_DAYS_AHEAD));
    }

    /** The account's active event types (Calendly's Free plan has one). */
    public function eventTypes(): array
    {
        $connection = $this->client->connection();

        return Cache::remember($this->eventTypesCacheKey($connection), self::EVENT_TYPES_CACHE_SECONDS, function () use ($connection) {
            $collection = $this->client->get('event_types', [
                'user' => $connection->calendly_user_uri,
                'active' => 'true',
                'count' => 100,
            ])['collection'] ?? [];

            return collect($collection)
                ->filter(fn (array $type) => ($type['active'] ?? false) === true)
                ->map(fn (array $type) => [
                    'uri' => $type['uri'],
                    'name' => $type['name'] ?? null,
                    'duration' => (int) ($type['duration'] ?? 0),
                    'kind' => $type['kind'] ?? null,
                    'scheduling_url' => $type['scheduling_url'] ?? null,
                    'locations' => $type['locations'] ?? [],
                ])
                ->values()
                ->all();
        });
    }

    /**
     * The event type's open slots on $data['date'] - a day in the viewer's
     * zone - leaving out those the host or the attendee already have an FCH
     * meeting in (Calendly does not know about those).
     */
    public function availableTimes(User $caller, array $data): array
    {
        $connection = $this->client->connection();
        $type = $this->eventType($data['event_type_uri']);
        $zone = AppointmentTime::zone($data['timezone'] ?? null) ?? AppointmentTime::viewerZone();

        $dayStart = Carbon::parse($data['date'], $zone)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $from = $dayStart->copy()->max(now()->addMinute()->setTimezone($zone));

        if ($from->gte($dayEnd)) {
            return [];
        }

        $slots = $this->client->get('event_type_available_times', [
            'event_type' => $type['uri'],
            'start_time' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $dayEnd->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ])['collection'] ?? [];

        $attendeeId = $this->isHost($caller, $connection) ? ($data['assigned_for'] ?? null) : $caller->id;
        $busy = $this->busyTimes([(int) $connection->connected_by, (int) $attendeeId], $dayStart, $dayEnd);
        $minutes = max(1, $type['duration']);

        return collect($slots)
            ->filter(fn (array $slot) => ($slot['status'] ?? 'available') === 'available' && ! empty($slot['start_time']))
            ->map(fn (array $slot) => Carbon::parse($slot['start_time'])->utc())
            ->reject(fn (Carbon $start) => $busy->contains(
                fn (array $period) => $period[0]->lt($start->copy()->addMinutes($minutes)) && $start->lt($period[1])
            ))
            ->map(fn (Carbon $start) => [
                'start_time' => $start->format('Y-m-d\TH:i:s\Z'),
                'local_time' => $start->copy()->setTimezone($zone)->format(AppointmentTime::STORED_FORMAT),
                'label' => $start->copy()->setTimezone($zone)->format('g:i A'),
            ])
            ->values()
            ->all();
    }

    /** Books the slot in Calendly and returns the appointment it is kept as. */
    public function book(User $caller, array $data): Appointment
    {
        $connection = $this->client->connection();
        $attendee = $this->inviteeFor($caller, $connection, $data['assigned_for'] ?? null);
        $type = $this->eventType($data['event_type_uri']);
        $zone = AppointmentTime::zone($data['timezone'] ?? null) ?? 'UTC';

        $body = [
            'event_type' => $type['uri'],
            'start_time' => Carbon::parse($data['start_time'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'invitee' => [
                'name' => $attendee->name ?: $attendee->email,
                'email' => $attendee->email,
                'timezone' => $zone,
            ],
        ];

        $location = $type['locations'][0] ?? null;
        if (is_array($location) && ! empty($location['kind'])) {
            $body['location'] = array_filter([
                'kind' => $location['kind'],
                'location' => $location['location'] ?? null,
            ]);
        }

        $invitee = $this->client->post('invitees', $body)['resource'] ?? [];
        $event = $this->client->get($invitee['event'])['resource'] ?? [];

        $appointment = $this->sync->upsert($event, $invitee);
        if (! $appointment) {
            throw new CalendlyException(__('calendly.refused'), 502);
        }

        $appointment->update(array_filter([
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'meeting_type' => $data['meeting_type'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_CREATE);

        return $appointment->fresh(['scheduledBy', 'assignedFor.entity']);
    }

    /** A webhook delivery: false when its signature does not hold. */
    public function handleWebhook(string $body, ?string $signature): bool
    {
        if (! CalendlyWebhookSignature::isValid($signature, $body, config('services.calendly.webhook_signing_key'), now()->timestamp)) {
            return false;
        }

        $delivery = json_decode($body, true);
        if (is_array($delivery)) {
            $this->sync->handleWebhook($delivery);
        }

        return true;
    }

    /** One of the account's active event types; refused when it is not. */
    private function eventType(string $uri): array
    {
        $type = collect($this->eventTypes())->firstWhere('uri', $uri);

        if (! $type) {
            throw ValidationException::withMessages(['event_type_uri' => __('calendly.unknown_event_type')]);
        }

        return $type;
    }

    /** Who the Calendly invitee is: the person the host picked, or the caller booking with the host. */
    private function inviteeFor(User $caller, CalendlyConnection $connection, $assignedFor): User
    {
        if (! $this->isHost($caller, $connection)) {
            if (! $this->mayBookWithHost($caller, $connection)) {
                throw CalendlyException::forbidden();
            }

            return $caller;
        }

        if (empty($assignedFor)) {
            throw ValidationException::withMessages(['assigned_for' => __('calendly.attendee_required')]);
        }

        if (! $this->appointmentService->canBookWith($caller, (int) $assignedFor)) {
            throw ValidationException::withMessages(['assigned_for' => __('appointment.assigned_for_invalid')]);
        }

        return User::findOrFail($assignedFor);
    }

    private function isHost(User $user, CalendlyConnection $connection): bool
    {
        return (int) $connection->connected_by === (int) $user->id;
    }

    private function mayBookWithHost(User $user, CalendlyConnection $connection): bool
    {
        return $this->isHost($user, $connection)
            || $this->appointmentService->canBookWith($user, (int) $connection->connected_by);
    }

    /**
     * The live meetings of these people around the day, as [start, end] UTC.
     *
     * @return Collection<int, array{0: Carbon, 1: Carbon}>
     */
    private function busyTimes(array $people, Carbon $dayStart, Carbon $dayEnd): Collection
    {
        $people = array_values(array_filter($people));

        return Appointment::query()
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            ->where(fn ($q) => $q->whereIn('scheduled_by', $people)->orWhereIn('assigned_for', $people))
            // A wide window in SQL, the exact overlap in PHP - no database-specific date arithmetic.
            ->whereBetween('appointment_date', [
                $dayStart->copy()->utc()->subDay()->format(AppointmentTime::STORED_FORMAT),
                $dayEnd->copy()->utc()->format(AppointmentTime::STORED_FORMAT),
            ])
            ->get(['appointment_date', 'duration_minutes'])
            ->map(function (Appointment $appointment) {
                $start = Carbon::parse($appointment->appointment_date, 'UTC');

                return [$start, $start->copy()->addMinutes(max(1, (int) $appointment->duration_minutes))];
            });
    }

    private function eventTypesCacheKey(CalendlyConnection $connection): string
    {
        return 'calendly:event-types:'.$connection->id.':'.md5($connection->calendly_user_uri);
    }
}
