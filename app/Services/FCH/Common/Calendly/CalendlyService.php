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
use App\Services\Global\Calendly\CalendlyEventMapper;
use Illuminate\Support\Arr;
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

    /** Sync now, the way picked: Calendly into FCH, FCH into Calendly, or both. */
    public const SYNC_PULL = 'pull';

    public const SYNC_PUSH = 'push';

    public const SYNC_BOTH = 'both';

    public const SYNC_DIRECTIONS = [self::SYNC_PULL, self::SYNC_PUSH, self::SYNC_BOTH];

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
     * account) the one named: its Client ID, whether a Client Secret and a
     * Webhook Signing Key are saved - never the secrets, nor any part of them
     * (stored encrypted and write-only, as OpenAI Configuration's API key: a
     * new value replaces one, blank keeps it) - what to enter on
     * its Calendly app, and its connection. Only those two accounts get here
     * (managedPartnerId).
     */
    public function settings(User $user, ?int $partnerId = null): array
    {
        $partnerId = $this->managedPartnerId($user, $partnerId);
        $connection = CalendlyConnection::forPartner($partnerId);
        $connected = (bool) $connection?->isActive();

        return [
            'partner_id' => $partnerId,
            'client_id' => $connection?->client_id,
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

        [$user, $connection, $codeVerifier] = $issued;

        if ($error || ! $code) {
            return $this->backToPortal($user, $connection, 'error');
        }

        try {
            $connection = $this->oauth->connect($user, $connection, $code, $codeVerifier);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Connecting failed', ['user_id' => $user->id, 'connection_id' => $connection->id, 'error' => $e->getMessage()]);

            // The portal says why: that Calendly account is connected in FCH already.
            $inUse = $e instanceof CalendlyException && $e->getMessage() === __('calendly.account_in_use');

            return $this->backToPortal($user, $connection, $inUse ? 'in_use' : 'error');
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
        return $this->syncConnection($this->connectionToSync($user, $partnerId));
    }

    /** The Calendly the caller syncs (see syncNow); throws when they may sync none. */
    private function connectionToSync(User $user, ?int $partnerId): ?CalendlyConnection
    {
        if (CalendlyConnection::isBorrower($user)) {
            if ($partnerId && (int) $partnerId !== (int) $user->partner_id) {
                throw CalendlyException::forbidden();
            }

            return CalendlyConnection::forUser($user);
        }

        $partnerId = $partnerId && (int) $partnerId !== (int) $user->partner_id
            ? $this->managedPartnerId($user, $partnerId)
            : ($this->maySync($user) ? (int) $user->partner_id : throw CalendlyException::forbidden());

        return CalendlyConnection::forPartner($partnerId);
    }

    /**
     * Sync now, the way picked, for the dates the calendar shows (else this month):
     *  - pull: Calendly's meetings come into FCH, and the meetings others set
     *    with the caller in FCH go into the caller's Calendly;
     *  - push: the meetings the caller set in FCH go into Calendly - the
     *    caller's, and the other person's own Calendly when they have one;
     *  - both: pull, then push.
     * Only upcoming meetings go to Calendly (it books nothing in the past).
     * One Calendly refusing a meeting skips that meeting; the daily booking
     * limit stops the rest.
     *
     * @return array{direction: string, from: string, to: string, pulled: int, pushed: int, failed: int, errors: list<array{appointment_id: int, subject: ?string, message: string}>}
     */
    public function syncDirected(User $user, ?int $partnerId, string $direction, ?string $from, ?string $to, ?string $timezone): array
    {
        $connection = $this->connectionToSync($user, $partnerId) ?? throw CalendlyException::notConnected();
        $client = $this->client->forConnection($connection);
        $client->connection();

        $zone = AppointmentTime::zone($timezone) ?? ($connection->timezone ?: 'UTC');
        $start = $from ? Carbon::parse($from, $zone)->startOfDay() : now($zone)->startOfMonth();
        $end = $to ? Carbon::parse($to, $zone)->endOfDay() : $start->copy()->endOfMonth();

        $pulls = in_array($direction, [self::SYNC_PULL, self::SYNC_BOTH], true);
        $pushes = in_array($direction, [self::SYNC_PUSH, self::SYNC_BOTH], true);

        // FCH meetings go only into the caller's own Calendly - not one the host account configures for another partner.
        $own = $this->hostsWith($user, $connection) && CalendlyConnection::forUser($user)?->id === $connection->id;
        if ($pushes && ! $own) {
            throw ValidationException::withMessages(['direction' => __('calendly.push_needs_own')]);
        }

        $result = ['direction' => $direction, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'pulled' => 0, 'pushed' => 0, 'failed' => 0, 'errors' => []];

        if ($pulls) {
            Cache::forget($this->eventTypesCacheKey($connection));
            $result['pulled'] = $this->sync->syncRange($connection, $start, $end);
        }

        if (! $own) {
            return $result;
        }

        // Upcoming FCH meetings of the caller's in the dates (Calendly books nothing in the past).
        $meetings = Appointment::query()
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            ->whereNotNull('scheduled_by')
            ->whereNotNull('assigned_for')
            ->whereColumn('scheduled_by', '!=', 'assigned_for')
            ->where('appointment_date', '>', now()->addMinute()->utc()->format(AppointmentTime::STORED_FORMAT))
            ->whereBetween('appointment_date', [$start->copy()->utc()->format(AppointmentTime::STORED_FORMAT), $end->copy()->utc()->format(AppointmentTime::STORED_FORMAT)])
            ->where(fn ($q) => $q->where('scheduled_by', $user->id)->orWhere('assigned_for', $user->id))
            ->orderBy('appointment_date')
            ->get();

        foreach ($meetings as $appointment) {
            $organiser = (int) $appointment->scheduled_by === (int) $user->id;
            $other = User::find((int) ($organiser ? $appointment->assigned_for : $appointment->scheduled_by));
            if (! $other) {
                continue;
            }

            try {
                // Pull: set with the caller by someone else - into the caller's Calendly.
                if ($pulls && ! $organiser) {
                    $result['pushed'] += $this->ensureInCalendly($appointment, $connection, $other, $zone);
                }

                // Push: set by the caller - into the caller's Calendly (the other person
                // invited), and into the other person's own, the caller invited.
                if ($pushes && $organiser) {
                    $result['pushed'] += $this->ensureInCalendly($appointment, $connection, $other, $zone);
                    if ($theirs = $this->mirrorConnectionFor($other, $connection)) {
                        $result['pushed'] += $this->ensureInCalendly($appointment->fresh(), $theirs, $user, $zone);
                    }
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = [
                    'appointment_id' => $appointment->id,
                    'subject' => $appointment->subject,
                    'message' => $e instanceof CalendlyException || $e instanceof ValidationException ? $e->getMessage() : __('calendly.refused'),
                ];
                Log::warning('[CALENDLY] Not synced to Calendly', ['appointment_id' => $appointment->id, 'direction' => $direction, 'error' => $e->getMessage()]);

                // Calendly's booking limit (the Free plan's per day): the rest would be refused too.
                if ($e instanceof CalendlyException && $e->calendlyStatus() === 429) {
                    break;
                }
            }
        }

        $connection->update(['last_synced_at' => now()]);

        return $result;
    }

    /**
     * Puts the appointment in $target's Calendly, $invitee its invitee: as its
     * Calendly meeting when it has none yet (an FCH-only appointment), else as
     * the other person's copy. 1 when booked; 0 when it is there already, or
     * both of its places are taken.
     */
    private function ensureInCalendly(Appointment $appointment, CalendlyConnection $target, User $invitee, string $zone): int
    {
        if (! $target->isActive() || blank($target->calendly_user_uri)
            || (int) $appointment->calendly_connection_id === (int) $target->id
            || (int) $appointment->calendly_mirror_connection_id === (int) $target->id) {
            return 0;
        }

        $start = Carbon::parse($appointment->appointment_date, 'UTC')->startOfMinute();
        $meetingZone = AppointmentTime::zone($appointment->timezone) ?? $zone;

        // Its Calendly meeting is in a connection since removed (e.g. Calendly connected
        // again as a new connection): no one can reach it any more - its place is free.
        $primaryGone = $appointment->calendly_event_uri
            && ! CalendlyConnection::whereKey($appointment->calendly_connection_id)->exists();

        if (! $appointment->calendly_event_uri || $primaryGone) {
            $this->guarded($target, function () use ($appointment, $target, $invitee, $start, $meetingZone) {
                $booked = $this->bookInto($target, $invitee, (string) $appointment->subject, (string) $appointment->description, $start, $meetingZone, (int) $appointment->duration_minutes ?: null);
                $event = $this->client->forConnection($target)->get($booked['event'])['resource'] ?? [];
                // Recorded while still guarded: never taken in again as an appointment of its own.
                $appointment->update(array_merge(
                    Arr::except(CalendlyEventMapper::attributes($event, $booked, $meetingZone), ['subject']),
                    ['calendly_connection_id' => $target->id],
                ));
            });

            return 1;
        }

        // The other copy's place: taken, unless its connection has been removed too.
        if ($appointment->calendly_mirror_event_uri
            && CalendlyConnection::whereKey($appointment->calendly_mirror_connection_id)->exists()) {
            return 0;
        }

        $this->guarded($target, function () use ($appointment, $target, $invitee, $start, $meetingZone) {
            $booked = $this->bookInto($target, $invitee, (string) $appointment->subject, (string) $appointment->description, $start, $meetingZone, (int) $appointment->duration_minutes ?: null);
            $appointment->update([
                'calendly_mirror_event_uri' => $booked['event'],
                'calendly_mirror_invitee_uri' => $booked['uri'] ?? null,
                'calendly_mirror_connection_id' => $target->id,
            ]);
        });

        return 1;
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
        return $this->eventTypesOf($this->clientFor($user));
    }

    /** The active event types of the Calendly the client is for (cached briefly). */
    private function eventTypesOf(CalendlyClient $client): array
    {
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
        $busy = $this->busyTimes($people, $dayStart, $dayEnd, ((int) ($data['except_appointment_id'] ?? 0)) ?: null);
        // The person invited may have a Calendly of their own (their meetings and the calendars
        // connected to it): busy there is busy here too.
        if ($this->hostsWith($caller, $client->connection()) && ! empty($data['assigned_for'])) {
            $busy = $busy->toBase()->merge($this->calendlyBusyOf(User::find((int) $data['assigned_for']), $client->connection(), $from, $dayEnd));
        }
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

        // Named after the FCH title when Calendly allows it (a one-off meeting);
        // else on the event type, its name the event type's.
        $invitee = $this->bookUnderTitle($client, $connection, $type, $body, $data, $zone, $start)
            ?? ($client->post('invitees', $body)['resource'] ?? []);
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

        // Calendly lists a meeting only in its host's account: the person invited, with a
        // Calendly of their own connected, gets it booked there too, the caller its invitee.
        if ($isTeam && ($mirror = $this->mirrorConnectionFor($attendee, $connection))) {
            $this->bookMirror($appointment, $mirror, $caller, $start, $zone);
        }

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
    /** Cache key marking a Calendly meeting FCH is replacing: its cancellation must not remove the appointment. */
    public const MOVING_CACHE_PREFIX = 'calendly:moving:';

    /**
     * Set while a meeting is being booked into a connection as the other
     * person's copy: a new meeting it reports then (webhook, sync) is not
     * taken in as an appointment of its own - the next sync takes in any
     * other once the copy is recorded.
     */
    public const MIRRORING_CACHE_PREFIX = 'calendly:mirroring:';

    /**
     * An upcoming Calendly meeting whose title or time is changed in FCH,
     * changed in Calendly too. Calendly cannot edit a booked meeting, so it is
     * replaced: the new time is checked first (open in Calendly, free in FCH),
     * the old meeting cancelled, and a new one booked - named after the new
     * title, for the same person and place. Should that booking fail, the old
     * meeting is booked again and the change refused. Returns the appointment
     * columns the new meeting brings (empty when nothing goes to Calendly).
     *
     * @param  array  $data  the FCH update: appointment_date (UTC), subject
     */
    public function moveMeeting(Appointment $appointment, array $data, bool $evenIfUnchanged = false): array
    {
        $oldStart = Carbon::parse($appointment->appointment_date, 'UTC')->startOfMinute();
        $newStart = isset($data['appointment_date']) ? Carbon::parse($data['appointment_date'], 'UTC')->startOfMinute() : $oldStart->copy();
        $oldTitle = (string) $appointment->subject;
        $newTitle = trim((string) ($data['subject'] ?? $oldTitle));
        $timeChanged = ! $newStart->equalTo($oldStart);

        // Nothing for Calendly (unless asked to book it again, e.g. under its FCH title);
        // or a past or cancelled meeting, which it no longer changes.
        if ((! $evenIfUnchanged && ! $timeChanged && $newTitle === $oldTitle) || ! $appointment->isFromCalendly() || ! $appointment->isCancellable()) {
            return [];
        }

        $connection = CalendlyConnection::find($appointment->calendly_connection_id);
        if (! $connection?->isActive()) {
            throw ValidationException::withMessages(['appointment_date' => __('calendly.move_not_connected')]);
        }

        $client = $this->client->forConnection($connection);
        try {
            $event = $client->get($appointment->calendly_event_uri)['resource'] ?? [];
        } catch (CalendlyException $e) {
            // On another Calendly account than the one now connected (403), or gone (404):
            // this connection cannot change it - the change is made in FCH only.
            if (! in_array($e->calendlyStatus(), [403, 404], true)) {
                throw $e;
            }
            Log::warning('[CALENDLY] Changed in FCH only - not on the connected Calendly account', ['appointment_id' => $appointment->id, 'status' => $e->calendlyStatus()]);

            return [];
        }
        $invitee = $appointment->calendly_invitee_uri
            ? ($client->get($appointment->calendly_invitee_uri)['resource'] ?? [])
            : ($client->get($appointment->calendly_event_uri.'/invitees', ['count' => 1])['collection'][0] ?? []);
        if (empty($invitee['email'])) {
            throw new CalendlyException(__('calendly.refused'), 502);
        }

        $zone = AppointmentTime::zone($invitee['timezone'] ?? null) ?? AppointmentTime::zone($appointment->timezone) ?? ($connection->timezone ?: 'UTC');
        $type = $this->eventTypesOf($client)[0] ?? throw ValidationException::withMessages(['appointment_date' => __('calendly.no_event_types')]);

        if ($timeChanged) {
            $this->ensureOpenForMove($client, $type, $appointment, $newStart, $zone);
        }

        // The same person and place as the meeting replaced.
        $body = [
            'event_type' => $type['uri'],
            'invitee' => [
                'name' => $invitee['name'] ?? $invitee['email'],
                'email' => $invitee['email'],
                'timezone' => $zone,
            ],
        ];
        $kind = $this->chosenLocation($type, null)['kind'] ?? null;
        $where = trim((string) ($event['location']['location'] ?? ''));
        if ($kind && in_array($kind, self::INVITEE_LOCATION_KINDS, true) && $where !== '') {
            $body['location'] = ['kind' => $kind, 'location' => $where];
        }
        $description = (string) ($data['description'] ?? $appointment->description ?? '');
        $book = function (Carbon $start, string $title) use ($client, $connection, $type, $body, $zone, $description): array {
            $at = $body + ['start_time' => $start->format('Y-m-d\TH:i:s\Z')];

            return $this->bookUnderTitle($client, $connection, $type, $at, ['subject' => $title, 'description' => $description], $zone, $start)
                ?? ($client->post('invitees', $at)['resource'] ?? []);
        };

        // Its cancellation (webhook, sync) must not take the appointment off FCH.
        Cache::put(self::MOVING_CACHE_PREFIX.$appointment->calendly_event_uri, true, now()->addMinutes(15));
        $client->post($appointment->calendly_event_uri.'/cancellation', ['reason' => __('calendly.moved_reason')]);

        try {
            $newInvitee = $book($newStart, $newTitle);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Changed meeting not booked - booking the old one again', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            try {
                $restored = $book($oldStart, $oldTitle);
                $restoredEvent = $client->get($restored['event'])['resource'] ?? [];
                $appointment->update(Arr::except(CalendlyEventMapper::attributes($restoredEvent, $restored, $zone), ['subject', 'status']));
            } catch (Throwable $restore) {
                Log::error('[CALENDLY] Old meeting could not be booked again', ['appointment_id' => $appointment->id, 'error' => $restore->getMessage()]);
            }

            throw $e instanceof CalendlyException || $e instanceof ValidationException ? $e : new CalendlyException(__('calendly.refused'), 502);
        }

        $newEvent = $client->get($newInvitee['event'])['resource'] ?? [];

        return array_merge(
            Arr::except(CalendlyEventMapper::attributes($newEvent, $newInvitee, $zone), ['subject', 'status']),
            ['subject' => $newTitle],
            $this->moveMirror($appointment, $newStart, $newTitle, $description, $zone),
        );
    }

    /**
     * The other person's own Calendly, to book their copy of the meeting in:
     * connected, and not the account the meeting is already in. Null otherwise.
     */
    private function mirrorConnectionFor(?User $person, CalendlyConnection $booking): ?CalendlyConnection
    {
        $own = $person ? CalendlyConnection::forUser($person) : null;

        return $own?->isActive()
            && $own->id !== $booking->id
            && filled($own->calendly_user_uri)
            && $own->calendly_user_uri !== $booking->calendly_user_uri
                ? $own
                : null;
    }

    /**
     * Books the meeting in the other person's own Calendly - same time and
     * title, $invitee (the one who booked) its invitee - so it shows in their
     * Calendly too. The appointment's mirror columns; none when Calendly
     * refuses (the meeting itself stands - it is only logged).
     */
    private function bookMirror(Appointment $appointment, CalendlyConnection $mirror, User $invitee, Carbon $start, string $zone, ?string $title = null, ?string $description = null): array
    {
        $title ??= (string) $appointment->subject;
        $description ??= (string) $appointment->description;

        try {
            return $this->guarded($mirror, function () use ($appointment, $mirror, $invitee, $start, $zone, $title, $description) {
                $booked = $this->bookInto($mirror, $invitee, $title, $description, $start, $zone, (int) $appointment->duration_minutes ?: null);
                $columns = [
                    'calendly_mirror_event_uri' => $booked['event'],
                    'calendly_mirror_invitee_uri' => $booked['uri'] ?? null,
                    'calendly_mirror_connection_id' => $mirror->id,
                ];
                // Recorded while still guarded: the copy is never taken in as an appointment of its own.
                $appointment->update($columns);

                return $columns;
            });
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Not booked in the other person\'s Calendly', ['appointment_id' => $appointment->id, 'connection_id' => $mirror->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Books a meeting in $target's Calendly - its host the account's owner,
     * $invitee the invitee - at $start under $title (a one-off meeting named
     * after it when Calendly allows, else the account's event type). The
     * invitee resource; throws when Calendly refuses.
     */
    private function bookInto(CalendlyConnection $target, User $invitee, string $title, string $description, Carbon $start, string $zone, ?int $minutes = null): array
    {
        $client = $this->client->forConnection($target);
        $type = $this->eventTypesOf($client)[0] ?? throw new CalendlyException(__('calendly.no_event_types'), 422);
        if ($minutes) {
            // The one-off meeting is as long as the FCH appointment.
            $type['duration'] = $minutes;
        }

        $body = [
            'event_type' => $type['uri'],
            'start_time' => $start->format('Y-m-d\TH:i:s\Z'),
            'invitee' => [
                'name' => $invitee->name ?: $invitee->email,
                'email' => $invitee->email,
                'timezone' => $zone,
            ],
        ];
        $booked = $this->bookUnderTitle($client, $target, $type, $body, ['subject' => $title, 'description' => $description], $zone, $start)
            ?? ($client->post('invitees', $body)['resource'] ?? []);

        if (empty($booked['event'])) {
            throw new CalendlyException(__('calendly.refused'), 502);
        }

        return $booked;
    }

    /** Runs $work with new meetings of $target not taken in on their own (see MIRRORING_CACHE_PREFIX). */
    private function guarded(CalendlyConnection $target, callable $work): mixed
    {
        $guard = self::MIRRORING_CACHE_PREFIX.$target->id;
        Cache::put($guard, true, now()->addMinutes(2));

        try {
            return $work();
        } finally {
            Cache::forget($guard);
        }
    }

    /** The other person's copy, moved with the meeting: the old one cancelled, a new one booked. */
    private function moveMirror(Appointment $appointment, Carbon $start, string $title, string $description, string $zone): array
    {
        if (! $appointment->calendly_mirror_event_uri) {
            return [];
        }

        $mirror = CalendlyConnection::find($appointment->calendly_mirror_connection_id);
        $this->cancelMirror($appointment, __('calendly.moved_reason'));
        $cleared = ['calendly_mirror_event_uri' => null, 'calendly_mirror_invitee_uri' => null, 'calendly_mirror_connection_id' => null];
        $appointment->update($cleared);
        $invitee = $mirror ? $this->otherPartyOf($appointment, $mirror) : null;

        if (! $mirror?->isActive() || ! $invitee) {
            return $cleared;
        }

        // Under the new title and description (the appointment takes them after this).
        return $this->bookMirror($appointment, $mirror, $invitee, $start, $zone, $title, $description) ?: $cleared;
    }

    /**
     * The person on the appointment who does not own $connection - the
     * invitee of a meeting booked in it. Null when neither can be told apart.
     */
    private function otherPartyOf(Appointment $appointment, CalendlyConnection $connection): ?User
    {
        $people = collect([$appointment->scheduled_by, $appointment->assigned_for])
            ->filter()
            ->unique()
            ->map(fn ($id) => User::find((int) $id))
            ->filter();

        return $people->first(fn (User $person) => CalendlyConnection::forUser($person)?->id !== $connection->id)
            ?? $people->first(fn (User $person) => (int) $person->id !== (int) $connection->connected_by);
    }

    /**
     * Cancels the other person's copy in their Calendly. Never throws: their
     * Calendly refusing does not hold up the meeting's own change.
     */
    public function cancelMirror(Appointment $appointment, ?string $reason = null): void
    {
        if (! $appointment->calendly_mirror_event_uri) {
            return;
        }

        $mirror = CalendlyConnection::find($appointment->calendly_mirror_connection_id);
        if (! $mirror?->isActive()) {
            Log::warning('[CALENDLY] Other person\'s copy not cancelled - their Calendly is not connected', ['appointment_id' => $appointment->id]);

            return;
        }

        try {
            $this->client->forConnection($mirror)->post($appointment->calendly_mirror_event_uri.'/cancellation', [
                'reason' => $reason ?? __('calendly.cancel_reason'),
            ]);
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Other person\'s copy not cancelled', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * An upcoming Calendly meeting's name in Calendly - to compare with its FCH
     * title. Null when its Calendly is not connected (it cannot be read).
     */
    public function calendlyNameOf(Appointment $appointment): ?string
    {
        $connection = CalendlyConnection::find($appointment->calendly_connection_id);
        if (! $connection?->isActive() || ! $appointment->isFromCalendly()) {
            return null;
        }

        return $this->client->forConnection($connection)->get($appointment->calendly_event_uri)['resource']['name'] ?? null;
    }

    /**
     * Gives an upcoming Calendly meeting its FCH title in Calendly: booked
     * again, same time and person, named after the title (Calendly cannot
     * rename a booked meeting). The appointment moves to the new meeting.
     */
    public function retitleInCalendly(Appointment $appointment): Appointment
    {
        $changes = $this->moveMeeting($appointment, [], true);
        if ($changes !== []) {
            $appointment->update(Arr::except($changes, ['subject']));
        }

        return $appointment->fresh();
    }

    /**
     * A new time for a Calendly meeting: one its Calendly offers that day, and
     * no other FCH meeting of either person then (the meeting itself aside).
     */
    private function ensureOpenForMove(CalendlyClient $client, array $type, Appointment $appointment, Carbon $start, string $zone): void
    {
        $local = $start->copy()->setTimezone($zone);
        $dayStart = $local->copy()->startOfDay();
        $dayEnd = $local->copy()->endOfDay();
        $from = $dayStart->copy()->max(now()->addMinute()->setTimezone($zone));

        $slots = $from->gte($dayEnd) ? [] : ($client->get('event_type_available_times', [
            'event_type' => $type['uri'],
            'start_time' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $dayEnd->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ])['collection'] ?? []);

        $open = collect($slots)
            ->filter(fn (array $slot) => ($slot['status'] ?? 'available') === 'available' && ! empty($slot['start_time']))
            ->map(fn (array $slot) => Carbon::parse($slot['start_time'])->utc());

        $minutes = max(1, (int) $type['duration']);
        $end = $start->copy()->addMinutes($minutes);
        $taken = Appointment::query()
            ->whereKeyNot($appointment->id)
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            ->where(fn ($q) => $q->whereIn('scheduled_by', array_filter([$appointment->scheduled_by, $appointment->assigned_for]))
                ->orWhereIn('assigned_for', array_filter([$appointment->scheduled_by, $appointment->assigned_for])))
            ->whereBetween('appointment_date', [$start->copy()->subDay()->format(AppointmentTime::STORED_FORMAT), $end->format(AppointmentTime::STORED_FORMAT)])
            ->get(['appointment_date', 'duration_minutes'])
            ->contains(function (Appointment $other) use ($start, $end) {
                $otherStart = Carbon::parse($other->appointment_date, 'UTC');

                return $otherStart->lt($end) && $start->lt($otherStart->copy()->addMinutes(max(1, (int) $other->duration_minutes)));
            });

        // Busy in the people's own Calendly (other than this one): taken too.
        if (! $taken) {
            $taken = collect([$appointment->scheduled_by, $appointment->assigned_for])
                ->filter()
                ->unique()
                ->flatMap(fn ($id) => $this->calendlyBusyOf(User::find((int) $id), $client->connection(), $start, $end, $appointment->calendly_mirror_event_uri))
                ->contains(fn (array $period) => $period[0]->lt($end) && $start->lt($period[1]));
        }

        if (! $taken && $open->contains(fn (Carbon $slot) => $slot->equalTo($start))) {
            return;
        }

        $labels = $open->map(fn (Carbon $slot) => $slot->copy()->setTimezone($zone)->format('g:i A'));
        throw ValidationException::withMessages(['appointment_date' => $labels->isEmpty()
            ? __('calendly.no_open_times', ['date' => $local->format('M j, Y')])
            : __('calendly.time_not_open', [
                'time' => $local->format('g:i A'),
                'times' => $labels->take(12)->implode(', ').($labels->count() > 12 ? ', …' : ''),
            ])]);
    }

    /**
     * Books the meeting under the FCH appointment title: Calendly names a
     * meeting after its event type, so a one-off meeting type named with the
     * title (same length, day and place) is made and the invitee booked on it.
     * Needs the event_types:write scope. Null - the caller books on the event
     * type instead - when there is no title, or Calendly refuses (4xx: no such
     * scope, plan, place...). Any other failure is thrown: the booking may have
     * been made, so booking again could make it twice.
     */
    private function bookUnderTitle(CalendlyClient $client, CalendlyConnection $connection, array $type, array $body, array $data, string $zone, Carbon $start): ?array
    {
        $title = trim((string) ($data['subject'] ?? ''));
        if ($title === '' || blank($connection->calendly_user_uri)) {
            return null;
        }

        $day = $start->copy()->setTimezone($zone);
        $makeOneOff = fn (?array $location) => $client->post('one_off_event_types', array_filter([
            'name' => mb_strimwidth($title, 0, 55, '…'),
            'host' => $connection->calendly_user_uri,
            'duration' => max(1, (int) $type['duration']),
            'timezone' => $zone,
            'date_setting' => [
                'type' => 'date_range',
                'start_date' => $day->toDateString(),
                'end_date' => $day->copy()->addDay()->toDateString(),
            ],
            'location' => $location,
        ]))['resource']['uri'] ?? null;

        // It has none of the event type's questions; the place goes as the one-off takes it.
        $named = $body;
        unset($named['location'], $named['questions_and_answers']);
        $notes = trim(html_entity_decode(strip_tags((string) ($data['description'] ?? '')), ENT_QUOTES | ENT_HTML5));
        [$location, $answer] = $this->oneOffLocation($body['location'] ?? null, $notes);

        try {
            try {
                $oneOff = $makeOneOff($location);
                if (! $oneOff) {
                    return null;
                }

                return $client->post('invitees', array_filter(array_merge($named, ['event_type' => $oneOff, 'location' => $answer])))['resource'] ?? null;
            } catch (CalendlyException $e) {
                // A place Calendly will not book a one-off with: the title still goes, without the place.
                if ($location === null || $e->calendlyStatus() !== 400) {
                    throw $e;
                }
                Log::info('[CALENDLY] Named meeting booked without its place - Calendly refused it', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
                $oneOff = $makeOneOff(null);
                if (! $oneOff) {
                    return null;
                }

                return $client->post('invitees', array_merge($named, ['event_type' => $oneOff]))['resource'] ?? null;
            }
        } catch (CalendlyException $e) {
            $status = $e->calendlyStatus();
            if ($status === null || $status < 400 || $status >= 500 || $status === 429) {
                throw $e;
            }

            Log::info('[CALENDLY] Booked on the event type - Calendly refused a meeting named after the title', [
                'connection_id' => $connection->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The event type's place for a one-off meeting type: [its location
     * setting, the invitee's answer when booking]. Its kinds are fewer, and
     * Calendly will not book a one-off with a "custom" or "ask invitee" place
     * ("invalid location choice") - a call we make (or a place the invitee
     * gives) goes as a "physical" place written out ("Phone call: <number>"),
     * which both people see as the location; the FCH description goes with it
     * as its additional info (a one-off has no questions to answer it in).
     *
     * @return array{0: ?array, 1: ?array}
     */
    private function oneOffLocation(?array $location, string $notes = ''): array
    {
        $kind = $location['kind'] ?? null;
        $where = trim((string) ($location['location'] ?? ''));
        $info = $notes !== '' ? ['additional_info' => mb_substr($notes, 0, 1000)] : [];

        return match ($kind) {
            null => [null, null],
            'outbound_call' => $where !== ''
                ? [['kind' => 'physical', 'location' => 'Phone call: '.$where] + $info, null]
                : [null, null],
            'ask_invitee', 'custom' => $where !== '' ? [['kind' => 'physical', 'location' => $where] + $info, null] : [null, null],
            'physical' => $where !== '' ? [['kind' => 'physical', 'location' => $where] + $info, null] : [null, null],
            'inbound_call' => $where !== '' ? [['kind' => 'inbound_call', 'phone_number' => $where], null] : [null, null],
            'zoom_conference', 'google_conference', 'microsoft_teams_conference', 'webex_conference', 'gotomeeting_conference' => [['kind' => $kind], null],
            default => [null, null],
        };
    }

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
    /**
     * When a person has a Calendly of their own (a borrower's, or their partner's)
     * other than the one being booked: its busy times from $from to $to - their
     * Calendly meetings and the calendars connected to it (Calendly's
     * user_busy_times, availability:read). Empty when they have none, or when
     * Calendly cannot be asked (logged; FCH's own meetings are still checked).
     *
     * @return Collection<int, array{0: Carbon, 1: Carbon}>
     */
    private function calendlyBusyOf(?User $person, CalendlyConnection $booking, Carbon $from, Carbon $to, ?string $exceptEventUri = null): Collection
    {
        if (! $person) {
            return collect();
        }

        $theirs = CalendlyConnection::isBorrower($person)
            ? CalendlyConnection::ownedByBorrower($person)
            : CalendlyConnection::forPartner($person->partner_id);
        if (! $theirs?->isActive() || (int) $theirs->id === (int) $booking->id || blank($theirs->calendly_user_uri)) {
            return collect();
        }

        $start = $from->copy()->max(now()->addMinute());
        if ($start->gte($to)) {
            return collect();
        }

        try {
            $busy = $this->client->forConnection($theirs)->get('user_busy_times', [
                'user' => $theirs->calendly_user_uri,
                'start_time' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'end_time' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])['collection'] ?? [];
        } catch (Throwable $e) {
            Log::warning('[CALENDLY] Invitee\'s own Calendly busy times not read', ['user_id' => $person->id, 'connection_id' => $theirs->id, 'error' => $e->getMessage()]);

            return collect();
        }

        return collect($busy)
            ->filter(fn ($period) => is_array($period) && ! empty($period['start_time']) && ! empty($period['end_time']))
            // The meeting's own copy in their Calendly (when it is the one being moved) is not busy.
            ->reject(fn (array $period) => $exceptEventUri && ($period['event']['uri'] ?? null) === $exceptEventUri)
            ->map(fn (array $period) => [Carbon::parse($period['start_time'])->utc(), Carbon::parse($period['end_time'])->utc()])
            ->values();
    }

    private function busyTimes(array $people, Carbon $dayStart, Carbon $dayEnd, ?int $exceptAppointmentId = null): Collection
    {
        $people = array_values(array_filter($people));

        return Appointment::query()
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            // The meeting being edited does not hold its own time.
            ->when($exceptAppointmentId, fn ($q, $id) => $q->whereKeyNot($id))
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
