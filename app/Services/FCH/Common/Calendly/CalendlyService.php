<?php

namespace App\Services\FCH\Common\Calendly;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Appointment\Appointment;
use App\Models\Calendly\CalendlyConnection;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Appointment\AppointmentService;
use App\Services\FCH\Common\Notification\Sms\SmsRecipient;
use App\Services\Global\Calendly\CalendlyClient;
use App\Services\Global\Calendly\CalendlyException;
use App\Services\Global\Calendly\CalendlyOAuthService;
use App\Services\Global\Calendly\CalendlySyncService;
use App\Services\Global\Calendly\CalendlyWebhookSignature;
use App\Services\Global\Common\PortalUrlResolver;
use App\Support\Appointment\AppointmentTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Calendly as the portals use it - one per partner: the host's, each WLP's,
 * each certified partner's, connected with one click through FCH's Calendly
 * OAuth app. The partner's team connects, syncs and disconnects it, and books
 * into it inviting the person they pick (O20); the partner's borrowers book
 * with it - the borrower is the invitee, the meeting is with the partner.
 */
class CalendlyService
{
    private const EVENT_TYPES_CACHE_SECONDS = 300;

    /**
     * Location kinds where the invitee says where to meet - for a phone call
     * the host makes, their number. Calendly refuses such a booking without it.
     */
    private const INVITEE_LOCATION_KINDS = ['outbound_call', 'ask_invitee'];

    /** How each Calendly location kind reads to the person booking. */
    private const LOCATION_LABELS = [
        'zoom_conference' => 'Zoom',
        'google_conference' => 'Google Meet',
        'microsoft_teams_conference' => 'Microsoft Teams',
        'webex_conference' => 'Webex',
        'gotomeeting_conference' => 'GoTo Meeting',
        'outbound_call' => 'Phone call (we call you)',
        'inbound_call' => 'Phone call (you call us)',
        'physical' => 'In person',
        'ask_invitee' => 'Invitee chooses the place',
        'custom' => 'Custom',
    ];

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
        private readonly PortalUrlResolver $portalUrls,
    ) {}

    /** The login's partner's Calendly: whether it is on, and what this login may do with it. */
    public function status(User $user): array
    {
        if (CalendlyConnection::isBorrower($user)) {
            return $this->borrowerStatus($user);
        }

        $connection = CalendlyConnection::forUser($user);
        $connected = (bool) $connection?->isActive();
        $isTeam = $this->isPartnerTeam($user);
        // The partner's team, and its borrowers (their calendar shows its meetings with them).
        $canSync = $connected && $this->maySync($user);

        return [
            // The partner's own Calendly app keys are saved (Settings > Calendly).
            'configured' => (bool) $connection?->hasKeys(),
            'connected' => $connected,
            'needs_reconnect' => $connection?->status === CalendlyConnection::STATUS_RECONNECT,
            // Only the partner's own account (host, WLP, certified) configures it - in
            // Settings > Calendly; its staff and its borrowers may sync it.
            'can_manage' => CalendlyConnection::isPartnerAccount($user),
            'can_sync' => $canSync,
            // The team books *for* someone it picks; a borrower is the invitee themselves.
            'is_host' => $connected && $isTeam,
            'can_book' => $connected,
            'name' => $connection?->name,
            'email' => $isTeam ? $connection?->email : null,
            'scheduling_url' => $connection?->scheduling_url,
            'connected_by' => $connected && $isTeam ? $connection->connectedBy?->name : null,
            'webhooks_active' => $isTeam && (bool) $connection?->webhook_subscription_uri,
            'last_synced_at' => $canSync ? $connection?->last_synced_at?->toIso8601String() : null,
        ];
    }

    /**
     * A borrower's Appointments page. Their own Calendly - connected, or which
     * they may connect through their partner's Calendly app - and the one they
     * book with: their own once connected, else their partner's (as the invitee).
     */
    private function borrowerStatus(User $user): array
    {
        $own = CalendlyConnection::ownedByBorrower($user);
        $partners = CalendlyConnection::forPartner($user->partner_id);
        $ownConnected = (bool) $own?->isActive();
        $booking = $ownConnected ? $own : $partners;
        $bookingConnected = (bool) $booking?->isActive();

        return [
            // The partner's Calendly app is set up: the borrower may connect through it.
            'configured' => (bool) $partners?->hasKeys(),
            // Their own Calendly is signed in.
            'connected' => $ownConnected,
            'needs_reconnect' => $own?->status === CalendlyConnection::STATUS_RECONNECT,
            'can_manage' => (bool) $partners?->hasKeys() || (bool) $own,
            'can_sync' => $bookingConnected,
            // Booking into their own, they invite the person they pick; with the partner's they are the invitee.
            'is_host' => $ownConnected,
            'can_book' => $bookingConnected,
            'books_with_partner' => ! $ownConnected && $bookingConnected,
            'partner_connected' => (bool) $partners?->isActive(),
            // Whose Calendly they book with until they connect their own (the advisor's account name).
            'partner_name' => $partners?->isActive() ? $partners->name : null,
            'name' => $own?->name,
            'email' => $own?->email,
            'scheduling_url' => $booking?->scheduling_url,
            'connected_by' => null,
            'webhooks_active' => $ownConnected && (bool) $own->webhook_subscription_uri,
            'last_synced_at' => $booking?->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * Settings > Calendly for a partner - the caller's own, or (the host
     * account) the one named: its saved app keys in full - Client ID, Client
     * Secret and Webhook Signing Key, so the partner account or the host can
     * see and change them - what to enter on its Calendly app, and its
     * connection. Only those two accounts get here (managedPartnerId).
     */
    public function settings(User $user, ?int $partnerId = null): array
    {
        $partnerId = $this->managedPartnerId($user, $partnerId);
        $connection = CalendlyConnection::forPartner($partnerId);
        $connected = (bool) $connection?->isActive();

        return [
            'partner_id' => $partnerId,
            'client_id' => $connection?->client_id,
            'client_secret' => $connection?->client_secret,
            'webhook_signing_key' => $connection?->webhook_signing_key,
            'has_client_secret' => filled($connection?->client_secret),
            'has_webhook_signing_key' => filled($connection?->webhook_signing_key),
            'configured' => (bool) $connection?->hasKeys(),
            'connected' => $connected,
            'needs_reconnect' => $connection?->status === CalendlyConnection::STATUS_RECONNECT,
            'name' => $connection?->name,
            'email' => $connection?->email,
            'connected_by' => $connected ? $connection->connectedBy?->name : null,
            'webhooks_active' => (bool) $connection?->webhook_subscription_uri,
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'redirect_uri' => $this->oauth->redirectUri(),
            'scopes' => array_values(array_filter(explode(' ', (string) config('services.calendly.scopes')))),
        ];
    }

    /**
     * Saves a partner's Calendly app keys. A blank secret or signing key keeps
     * the stored one; a different Client ID is another app, so the sign-in
     * made with the old one is ended first. A new signing key while connected
     * re-registers the webhook, so Calendly signs with the key now saved.
     */
    public function saveSettings(User $user, ?int $partnerId, array $keys): array
    {
        $partnerId = $this->managedPartnerId($user, $partnerId);
        $connection = CalendlyConnection::forPartner($partnerId) ?? new CalendlyConnection(['partner_id' => $partnerId]);

        $secret = trim((string) ($keys['client_secret'] ?? ''));
        if ($secret === '' && blank($connection->client_secret)) {
            throw ValidationException::withMessages(['client_secret' => __('calendly.client_secret_required')]);
        }

        $appChanged = $connection->exists && filled($connection->client_id) && $connection->client_id !== $keys['client_id'];
        if ($appChanged && $connection->isActive()) {
            $this->oauth->disconnect($connection);
            $connection->refresh();
        }

        $connection->client_id = $keys['client_id'];
        if ($secret !== '') {
            $connection->client_secret = $secret;
        }
        $signingKey = trim((string) ($keys['webhook_signing_key'] ?? ''));
        $signingKeyChanged = $signingKey !== '' && $signingKey !== (string) $connection->webhook_signing_key;
        if ($signingKeyChanged) {
            $connection->webhook_signing_key = $signingKey;
        }
        $connection->connected_by ??= $user->id;
        $connection->status ??= CalendlyConnection::STATUS_PENDING;
        $connection->save();
        Cache::forget($this->eventTypesCacheKey($connection));

        if ($signingKeyChanged && $connection->isActive()) {
            $this->oauth->resubscribeWebhooks($connection);
        }

        // Its borrowers sign in through the same app: they get its keys, and
        // another app ends their sign-in (made with the old one) too.
        CalendlyConnection::where('partner_id', $partnerId)->whereNotNull('borrower_id')->get()
            ->each(function (CalendlyConnection $borrowers) use ($appChanged, $connection) {
                if ($appChanged && $borrowers->isActive()) {
                    $this->oauth->disconnect($borrowers);
                }
                $borrowers->update(['client_id' => $connection->client_id, 'client_secret' => $connection->client_secret]);
            });

        return $this->settings($user, $partnerId);
    }

    /**
     * The Calendly sign-in link: for a partner, with its own saved app keys;
     * for a borrower, their own Calendly through their partner's app.
     */
    public function connect(User $user, ?int $partnerId = null): string
    {
        $connection = CalendlyConnection::isBorrower($user)
            ? $this->borrowersOwnFor($user)
            : CalendlyConnection::forPartner($this->managedPartnerId($user, $partnerId));

        if (! $connection?->hasKeys()) {
            throw ValidationException::withMessages(['client_id' => __('calendly.keys_required')]);
        }

        $this->oauth->ensureSigningKey($connection);
        Cache::forget($this->eventTypesCacheKey($connection));

        return $this->oauth->authorizeUrl($user, $connection);
    }

    /**
     * The browser coming back from Calendly. Returns where to send it: Settings
     * > Calendly for the account's own, the partner's profile for the host
     * connecting it on a partner's behalf - saying whether it worked.
     */
    public function handleCallback(?string $code, ?string $state, ?string $error): string
    {
        $issued = $this->oauth->consumeState($state);

        if (! $issued) {
            return $this->backToPortal(null, null, 'error');
        }

        [$user, $connection] = $issued;

        if ($error || ! $code) {
            return $this->backToPortal($user, $connection, 'error');
        }

        try {
            $connection = $this->oauth->connect($user, $connection, $code);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Connecting failed', ['user_id' => $user->id, 'connection_id' => $connection->id, 'error' => $e->getMessage()]);

            return $this->backToPortal($user, $connection, 'error');
        }

        try {
            $this->syncConnection($connection);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] First sync failed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
        }

        return $this->backToPortal($user, $connection, 'connected');
    }

    public function disconnect(User $user, ?int $partnerId = null): void
    {
        $connection = CalendlyConnection::isBorrower($user)
            ? CalendlyConnection::ownedByBorrower($user)
            : CalendlyConnection::forPartner($this->managedPartnerId($user, $partnerId));
        if ($connection) {
            Cache::forget($this->eventTypesCacheKey($connection));
        }

        $this->oauth->disconnect($connection);
    }

    /**
     * Brings in a partner's Calendly meetings from a week back to two months
     * ahead, and lists its event types afresh - one just made shows. The
     * partner's team syncs its own; a borrower the one they book with (their
     * own once connected, else their partner's); the host account any partner's.
     */
    public function syncNow(User $user, ?int $partnerId = null): int
    {
        if (CalendlyConnection::isBorrower($user)) {
            if ($partnerId && (int) $partnerId !== (int) $user->partner_id) {
                throw CalendlyException::forbidden();
            }

            return $this->syncConnection(CalendlyConnection::forUser($user));
        }

        $partnerId = $partnerId && (int) $partnerId !== (int) $user->partner_id
            ? $this->managedPartnerId($user, $partnerId)
            : ($this->maySync($user) ? (int) $user->partner_id : throw CalendlyException::forbidden());

        return $this->syncConnection(CalendlyConnection::forPartner($partnerId));
    }

    /**
     * A borrower's own connection, made (or brought up to date) to sign in
     * through their partner's Calendly app - null while the partner has not
     * saved its app keys.
     */
    private function borrowersOwnFor(User $user): ?CalendlyConnection
    {
        $partners = CalendlyConnection::forPartner($user->partner_id);
        if (! $partners?->hasKeys() || ! $user->entity_id) {
            throw ValidationException::withMessages(['client_id' => __('calendly.partner_keys_required')]);
        }

        $own = CalendlyConnection::firstOrNew(['borrower_id' => $user->entity_id]);
        $own->fill([
            'partner_id' => $user->partner_id,
            'client_id' => $partners->client_id,
            'client_secret' => $partners->client_secret,
        ]);
        $own->connected_by ??= $user->id;
        $own->status ??= CalendlyConnection::STATUS_PENDING;
        $own->save();

        return $own;
    }

    /**
     * The partner whose Calendly the caller configures: their own - only as
     * the host, WLP or certified partner account itself - or another one
     * named by the host account (Partners > partner > Configuration). Staff,
     * other users and borrowers configure none.
     */
    private function managedPartnerId(User $user, ?int $partnerId): int
    {
        if (! CalendlyConnection::isPartnerAccount($user)) {
            throw CalendlyException::forbidden();
        }

        if (! $partnerId || (int) $partnerId === (int) $user->partner_id) {
            return (int) $user->partner_id;
        }

        if (! CalendlyConnection::isHostAccount($user)) {
            throw CalendlyException::forbidden();
        }

        if (! Partner::whereKey($partnerId)->exists()) {
            throw new CalendlyException(__('partner.partner_not_found'), 404);
        }

        return (int) $partnerId;
    }


    /** One connection's sync - the hourly run goes through every connection with it. */
    public function syncConnection(?CalendlyConnection $connection): int
    {
        if (! $connection) {
            throw CalendlyException::notConnected();
        }

        Cache::forget($this->eventTypesCacheKey($connection));

        return $this->sync->syncRange($connection, now()->subDays(self::SYNC_DAYS_BACK), now()->addDays(self::SYNC_DAYS_AHEAD));
    }

    /** The partner's active event types (Calendly's Free plan has one). */
    public function eventTypes(User $user): array
    {
        $client = $this->clientFor($user);
        $connection = $client->connection();

        return Cache::remember($this->eventTypesCacheKey($connection), self::EVENT_TYPES_CACHE_SECONDS, function () use ($client, $connection) {
            $collection = $client->get('event_types', [
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
                    'location_kind' => $type['locations'][0]['kind'] ?? null,
                    'asks_invitee_location' => in_array($type['locations'][0]['kind'] ?? null, self::INVITEE_LOCATION_KINDS, true),
                    // Its free-text questions: the FCH title and description are answered in the first.
                    'text_questions' => collect($type['custom_questions'] ?? [])
                        ->filter(fn ($question) => is_array($question) && ($question['enabled'] ?? false)
                            && in_array($question['type'] ?? null, ['text', 'string'], true) && filled($question['name'] ?? null))
                        ->sortBy('position')
                        ->pluck('name')
                        ->values()
                        ->all(),
                    // Every place it offers (Calendly's "All options"), to pick from when booking.
                    'location_options' => collect($type['locations'] ?? [])
                        ->filter(fn ($location) => is_array($location) && ! empty($location['kind']))
                        ->map(fn (array $location) => [
                            'kind' => $location['kind'],
                            'label' => $this->locationLabel($location),
                            'asks_invitee' => in_array($location['kind'], self::INVITEE_LOCATION_KINDS, true),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * The event type's open slots on $data['date'] - a day in the viewer's
     * zone - leaving out those the caller or the attendee already have an FCH
     * meeting in (Calendly does not know about those).
     */
    public function availableTimes(User $caller, array $data): array
    {
        $client = $this->clientFor($caller);
        $client->connection();
        $type = $this->eventType($caller, $data['event_type_uri']);
        $zone = AppointmentTime::zone($data['timezone'] ?? null) ?? AppointmentTime::viewerZone();

        $dayStart = Carbon::parse($data['date'], $zone)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $from = $dayStart->copy()->max(now()->addMinute()->setTimezone($zone));

        if ($from->gte($dayEnd)) {
            return [];
        }

        $slots = $client->get('event_type_available_times', [
            'event_type' => $type['uri'],
            'start_time' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $dayEnd->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ])['collection'] ?? [];

        // A borrower meets the partner's connected user; the team member meets the person they pick.
        $people = $this->hostsWith($caller, $client->connection())
            ? [(int) $caller->id, (int) ($data['assigned_for'] ?? 0)]
            : [(int) $caller->id, (int) $client->connection()->connected_by];
        $busy = $this->busyTimes($people, $dayStart, $dayEnd);
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

    /**
     * Books the slot in the caller's partner's Calendly: the team invites the
     * person it picked; a borrower books with the partner, as the invitee.
     */
    public function book(User $caller, array $data): Appointment
    {
        $client = $this->clientFor($caller);
        $connection = $client->connection();
        // The caller owns the Calendly (the partner team, or a borrower in their own): they invite who they pick.
        $isTeam = $this->hostsWith($caller, $connection);
        $attendee = $isTeam ? $this->attendeeFor($caller, $data['assigned_for'] ?? null) : $caller;
        if (! $isTeam && ! $this->appointmentService->canBookWith($caller, (int) $connection->connected_by)) {
            throw CalendlyException::forbidden();
        }
        // The event type asked for, else the account's active one (Add Appointment never asks).
        $type = filled($data['event_type_uri'] ?? null)
            ? $this->eventType($caller, $data['event_type_uri'])
            : ($this->eventTypes($caller)[0] ?? throw ValidationException::withMessages(['start_time' => __('calendly.no_event_types')]));
        $zone = AppointmentTime::zone($data['timezone'] ?? null) ?? 'UTC';
        // A wall time ("2026-11-18 15:00:00") is read in the chosen zone; one with an offset or Z as given.
        $start = Carbon::parse($data['start_time'], $zone)->utc()->startOfMinute();
        $this->ensureOpenInCalendly($caller, $type, $start, $zone, $data['assigned_for'] ?? null);

        $body = [
            'event_type' => $type['uri'],
            'start_time' => $start->format('Y-m-d\TH:i:s\Z'),
            'invitee' => [
                'name' => $attendee->name ?: $attendee->email,
                'email' => $attendee->email,
                'timezone' => $zone,
            ],
        ];

        $location = $this->chosenLocation($type, $data['location_kind'] ?? null);
        if (is_array($location) && ! empty($location['kind'])) {
            // The invitee's answer (e.g. the number to call), else the event type's own place
            // (an in-person address, a custom place, the number to call for an inbound call).
            $asksInvitee = in_array($location['kind'], self::INVITEE_LOCATION_KINDS, true);
            $where = $asksInvitee
                ? trim((string) ($data['location'] ?? ''))
                : ($location['location'] ?? $location['phone_number'] ?? null);
            // A phone call to the invitee: their number from FCH unless one was typed.
            if ($location['kind'] === 'outbound_call' && $where === '') {
                $where = (string) app(SmsRecipient::class)->numberFor($attendee);
                if ($where === '') {
                    throw ValidationException::withMessages(['location' => __('calendly.phone_missing', ['name' => $attendee->name ?: $attendee->email])]);
                }
            }
            if ($asksInvitee && $where === '') {
                throw ValidationException::withMessages(['location' => __('calendly.location_required')]);
            }
            // Calendly calls this number: digits (7-15), optionally + and spaces, dashes, dots or brackets.
            if ($location['kind'] === 'outbound_call' && ! $this->isPhoneNumber($where)) {
                throw ValidationException::withMessages(['location' => __('calendly.phone_invalid')]);
            }

            $body['location'] = array_filter([
                'kind' => $location['kind'],
                'location' => $where,
            ]);
        }

        // Calendly names the meeting after its event type and takes no other name, so
        // the FCH title (and description) go in its first free-text question - shown
        // in the meeting's details and Calendly's emails.
        $question = $type['text_questions'][0] ?? null;
        $notes = trim(implode("\n\n", array_filter([
            trim((string) ($data['subject'] ?? '')),
            trim(html_entity_decode(strip_tags((string) ($data['description'] ?? '')), ENT_QUOTES | ENT_HTML5)),
        ])));
        if ($question && $notes !== '') {
            $body['questions_and_answers'] = [[
                'question' => $question,
                'answer' => mb_substr($notes, 0, 1000),
                'position' => 0,
            ]];
        }

        $invitee = $client->post('invitees', $body)['resource'] ?? [];
        $event = $client->get($invitee['event'])['resource'] ?? [];

        $appointment = $this->sync->upsert($connection, $event, $invitee);
        if (! $appointment) {
            throw new CalendlyException(__('calendly.refused'), 502);
        }

        // The team's booking: by the caller, with the person picked. A borrower's: with the
        // partner's connected user (as synced), the borrower the invitee.
        $appointment->update(array_filter([
            'scheduled_by' => $isTeam ? $caller->id : $connection->connected_by,
            'assigned_for' => $attendee->id,
            'entity_type' => $attendee->entity_type,
            'entity_id' => $attendee->entity_id,
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'meeting_type' => $data['meeting_type'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $this->activityLogService->logModelEvent($appointment, ActivityLog::LOG_TYPE_APPOINTMENT_CREATE);

        return $appointment->fresh(['scheduledBy', 'assignedFor.entity']);
    }

    /**
     * A webhook delivery for one connection: false when the connection is
     * unknown or the signature does not hold with its own signing key.
     */
    public function handleWebhook(int|string $connectionId, string $body, ?string $signature): bool
    {
        $connection = CalendlyConnection::find($connectionId);

        if (! $connection || blank($connection->webhook_signing_key)
            || ! CalendlyWebhookSignature::isValid($signature, $body, $connection->webhook_signing_key, now()->timestamp)) {
            return false;
        }

        $delivery = json_decode($body, true);
        if (is_array($delivery) && $connection->isActive()) {
            $this->sync->handleWebhook($connection, $delivery);
        }

        return true;
    }

    /** The caller's partner's Calendly (none while FCH's Calendly app is not set up). */
    private function clientFor(User $user): CalendlyClient
    {
        return $this->client->forConnection(CalendlyConnection::forUser($user));
    }

    /** One of a partner's team (host, WLP, certified) - not a borrower. */
    private function isPartnerTeam(User $user): bool
    {
        return (bool) $user->partner_id && ! CalendlyConnection::isBorrower($user);
    }

    /**
     * The caller books in their own Calendly - the partner's team in its
     * partner's, a borrower in their own - and so invites the person they
     * pick; a borrower booking with their partner's is the invitee.
     */
    private function hostsWith(User $caller, CalendlyConnection $connection): bool
    {
        return $connection->isBorrowers()
            ? CalendlyConnection::isBorrower($caller) && (int) $caller->entity_id === (int) $connection->borrower_id
            : $this->isPartnerTeam($caller);
    }

    /** Brings in their partner's Calendly meetings: its team, and its borrowers. */
    private function maySync(User $user): bool
    {
        return (bool) $user->partner_id;
    }

    /**
     * Back to the portal (a WLP's own domain when it has one): the
     * Appointments page, where the account connects its Calendly - the borrower
     * portal's /appointment for a borrower; the partner's Configuration tab for
     * the host connecting it on a partner's behalf (through the API).
     */
    private function backToPortal(?User $user, ?CalendlyConnection $connection, string $result): string
    {
        $forAnother = $user && $connection && (int) $connection->partner_id !== (int) $user->partner_id;
        $path = match (true) {
            $forAnother => '/partners/'.$connection->partner_id.'?tab=configuration&calendly='.$result,
            $user && CalendlyConnection::isBorrower($user) => '/appointment?calendly='.$result,
            default => '/appointments?calendly='.$result,
        };
        $url = $user ? $this->portalUrls->url($user, $path) : rtrim((string) config('app.frontend_partner_url'), '/').$path;

        // An address without its scheme (localhost:5174) would be read as a path on the API.
        return preg_match('#^https?://#i', $url) ? $url : 'http://'.ltrim($url, '/');
    }

    /** One of the caller's partner's active event types; refused when it is not. */
    /**
     * The picked time must be one Calendly offers for the event type that day
     * (and not taken in FCH): refused otherwise, saying which times are open,
     * before anything is sent to Calendly.
     */
    private function ensureOpenInCalendly(User $caller, array $type, Carbon $start, string $zone, mixed $assignedFor): void
    {
        $local = $start->copy()->setTimezone($zone);
        $open = $this->availableTimes($caller, array_filter([
            'event_type_uri' => $type['uri'],
            'date' => $local->toDateString(),
            'timezone' => $zone,
            'assigned_for' => $assignedFor,
        ]));

        if (collect($open)->contains('start_time', $start->format('Y-m-d\TH:i:s\Z'))) {
            return;
        }

        $times = collect($open)->pluck('label');
        throw ValidationException::withMessages(['start_time' => $times->isEmpty()
            ? __('calendly.no_open_times', ['date' => $local->format('M j, Y')])
            : __('calendly.time_not_open', [
                'time' => $local->format('g:i A'),
                'times' => $times->take(12)->implode(', ').($times->count() > 12 ? ', …' : ''),
            ])]);
    }

    private function eventType(User $caller, string $uri): array
    {
        $type = collect($this->eventTypes($caller))->firstWhere('uri', $uri);

        if (! $type) {
            throw ValidationException::withMessages(['event_type_uri' => __('calendly.unknown_event_type')]);
        }

        return $type;
    }

    /** The person the caller invites: one they may book with (O20). */
    private function attendeeFor(User $caller, $assignedFor): User
    {
        if (empty($assignedFor)) {
            throw ValidationException::withMessages(['assigned_for' => __('calendly.attendee_required')]);
        }

        if (! $this->appointmentService->canBookWith($caller, (int) $assignedFor)) {
            throw ValidationException::withMessages(['assigned_for' => __('appointment.assigned_for_invalid')]);
        }

        return User::findOrFail($assignedFor);
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

    /**
     * The place picked from the event type's options (its first when none was
     * picked); one it does not offer is refused before Calendly is asked.
     */
    private function chosenLocation(array $type, ?string $kind): ?array
    {
        $locations = array_values(array_filter($type['locations'] ?? [], fn ($location) => is_array($location) && ! empty($location['kind'])));

        if (blank($kind)) {
            return $locations[0] ?? null;
        }

        $chosen = collect($locations)->firstWhere('kind', $kind);
        if (! $chosen) {
            throw ValidationException::withMessages(['location_kind' => __('calendly.location_kind_invalid')]);
        }

        return $chosen;
    }

    private function locationLabel(array $location): string
    {
        $label = self::LOCATION_LABELS[$location['kind']] ?? ucfirst(str_replace('_', ' ', (string) $location['kind']));

        return $location['kind'] === 'physical' && filled($location['location'] ?? null)
            ? $label.': '.$location['location']
            : $label;
    }

    private function isPhoneNumber(?string $value): bool
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return preg_match('/^\+?[\d\s().\-]+$/', (string) $value) === 1
            && strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    private function eventTypesCacheKey(CalendlyConnection $connection): string
    {
        return 'calendly:event-types:'.$connection->id.':'.md5((string) $connection->calendly_user_uri);
    }
}
