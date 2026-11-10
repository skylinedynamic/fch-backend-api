<?php

namespace Tests\Integration\Calendly;

use App\Models\Appointment\Appointment;
use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use App\Services\Global\Calendly\CalendlyClient;
use App\Services\Global\Calendly\CalendlySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesCalendly;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The company's one Calendly account, mirrored into appointments: each
 * Calendly meeting is one appointment, scheduled by the host who connected the
 * account and with the FCH user whose email booked it - so it shows on both
 * their calendars.
 */
#[CoversClass(CalendlySyncService::class)]
#[CoversClass(CalendlyClient::class)]
class CalendlySyncServiceTest extends TestCase
{
    use FakesCalendly;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $host;

    private User $borrowerUser;

    private CalendlyConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->configureCalendly();
        $hostPartner = $this->makeHostPartner();
        $this->host = $this->makePartnerAdmin($hostPartner);
        $this->borrowerUser = $this->makeBorrowerUser($hostPartner);
        $this->connection = $this->connectCalendly($this->host);
    }

    private function service(): CalendlySyncService
    {
        return app(CalendlySyncService::class);
    }

    #[Test]
    public function a_calendly_meeting_booked_by_a_borrower_is_on_the_host_and_the_borrower(): void
    {
        $event = $this->calendlyEvent('EV1', now()->addDays(3)->setTime(15, 0));
        $invitee = $this->calendlyInvitee($event, strtoupper($this->borrowerUser->email), 'Jane Borrower');

        $appointment = $this->service()->upsert($event, $invitee);

        $this->assertSame(Appointment::SOURCE_CALENDLY, $appointment->source);
        $this->assertSame($this->host->id, (int) $appointment->scheduled_by);
        $this->assertSame($this->borrowerUser->id, (int) $appointment->assigned_for);
        $this->assertSame($this->borrowerUser->entity_type, $appointment->entity_type);
        $this->assertSame((int) $this->borrowerUser->entity_id, (int) $appointment->entity_id);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $appointment->join_url);
        $this->assertMatchesRegularExpression('/^APP-\d{4}$/', $appointment->appointment_code);
        $this->assertSame(1, $appointment->details()->count());
    }

    #[Test]
    public function a_meeting_with_someone_who_is_not_an_fch_user_keeps_their_name_and_email(): void
    {
        $event = $this->calendlyEvent('EV2', now()->addDays(3));
        $invitee = $this->calendlyInvitee($event, 'outsider@example.test', 'Out Sider');

        $appointment = $this->service()->upsert($event, $invitee);

        $this->assertNull($appointment->assigned_for);
        $this->assertSame('Out Sider', $appointment->invitee_name);
        $this->assertSame('outsider@example.test', $appointment->invitee_email);
    }

    #[Test]
    public function the_same_meeting_seen_twice_is_one_appointment_and_keeps_what_fch_set(): void
    {
        $event = $this->calendlyEvent('EV3', now()->addDays(3)->setTime(10, 0));
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane');

        $first = $this->service()->upsert($event, $invitee);
        $first->update(['subject' => 'Funding review', 'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW]);

        $moved = $this->calendlyEvent('EV3', now()->addDays(4)->setTime(11, 0));
        $second = $this->service()->upsert($moved, $invitee);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Appointment::where('calendly_event_uri', $event['uri'])->count());
        $this->assertSame('Funding review', $second->subject);
        $this->assertSame(Appointment::MEETING_TYPE_BUSINESS_REVIEW, $second->meeting_type);
        $this->assertSame(now()->addDays(4)->setTime(11, 0)->utc()->format('Y-m-d H:i:s'), $second->appointment_date);
    }

    #[Test]
    public function a_meeting_cancelled_in_calendly_is_cancelled_here(): void
    {
        $event = $this->calendlyEvent('EV4', now()->addDays(3));
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane');
        $this->service()->upsert($event, $invitee);

        $appointment = $this->service()->upsert(
            array_merge($event, ['status' => 'canceled']),
            array_merge($invitee, ['status' => 'canceled'])
        );

        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->status);
    }

    #[Test]
    public function a_meeting_deleted_in_fch_is_not_brought_back_by_calendly(): void
    {
        $event = $this->calendlyEvent('EV5', now()->addDays(3));
        $invitee = $this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane');
        $this->service()->upsert($event, $invitee)->delete();

        $this->service()->upsert($event, $invitee);

        $this->assertSame(0, Appointment::where('calendly_event_uri', $event['uri'])->count());
        $this->assertSame(1, Appointment::withTrashed()->where('calendly_event_uri', $event['uri'])->count());
    }

    #[Test]
    public function an_event_of_another_calendly_user_is_ignored(): void
    {
        $event = $this->calendlyEvent('EV6', now()->addDays(3), [
            'event_memberships' => [['user' => 'https://api.calendly.com/users/SOMEONE-ELSE']],
        ]);

        $this->assertNull($this->service()->upsert($event, $this->calendlyInvitee($event, 'x@example.test', 'X')));
        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function a_rescheduled_meeting_carries_what_fch_set_onto_the_new_event(): void
    {
        $old = $this->calendlyEvent('EV7', now()->addDays(3));
        $oldInvitee = $this->calendlyInvitee($old, $this->borrowerUser->email, 'Jane');
        $this->service()->upsert($old, $oldInvitee)->update([
            'subject' => 'Funding review',
            'description' => 'Bring bank statements',
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
        ]);

        $new = $this->calendlyEvent('EV8', now()->addDays(5));
        $newInvitee = $this->calendlyInvitee($new, $this->borrowerUser->email, 'Jane', ['old_invitee' => $oldInvitee['uri']]);
        $appointment = $this->service()->upsert($new, $newInvitee);

        $this->assertSame('Funding review', $appointment->subject);
        $this->assertSame('Bring bank statements', $appointment->description);
        $this->assertSame(Appointment::MEETING_TYPE_BUSINESS_REVIEW, $appointment->meeting_type);
    }

    #[Test]
    public function a_sync_pulls_every_page_of_meetings_with_their_invitees(): void
    {
        $first = $this->calendlyEvent('EVA', now()->addDays(2));
        $second = $this->calendlyEvent('EVB', now()->addDays(6));

        Http::fake([
            "{$this->calendlyApi}/scheduled_events/EVA/invitees*" => Http::response(['collection' => [$this->calendlyInvitee($first, $this->borrowerUser->email, 'Jane')]]),
            "{$this->calendlyApi}/scheduled_events/EVB/invitees*" => Http::response(['collection' => [$this->calendlyInvitee($second, 'outsider@example.test', 'Out')]]),
            "{$this->calendlyApi}/scheduled_events?*page_token=NEXT*" => Http::response(['collection' => [$second], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [$first], 'pagination' => ['next_page_token' => 'NEXT']]),
        ]);

        $count = $this->service()->syncRange(now()->subDays(7), now()->addDays(60));

        $this->assertSame(2, $count);
        $this->assertSame(2, Appointment::where('source', Appointment::SOURCE_CALENDLY)->count());
        $this->assertNotNull($this->connection->fresh()->last_synced_at);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/scheduled_events?")
            && $request['user'] === $this->calendlyUserUri
            && $request->hasHeader('Authorization', 'Bearer access-token-1'));
    }

    #[Test]
    public function an_expired_token_is_refreshed_once_and_the_new_tokens_are_kept_encrypted(): void
    {
        $this->connection->update(['token_expires_at' => now()->subMinute()]);

        Http::fake([
            "{$this->calendlyAuth}/oauth/token" => Http::response([
                'access_token' => 'access-token-2',
                'refresh_token' => 'refresh-token-2',
                'expires_in' => 7200,
                'token_type' => 'Bearer',
            ]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
        ]);

        $this->service()->syncRange(now()->subDay(), now()->addDay());

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyAuth}/oauth/token"
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token-1');
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->calendlyApi}/scheduled_events")
            && $request->hasHeader('Authorization', 'Bearer access-token-2'));

        $fresh = $this->connection->fresh();
        $this->assertSame('access-token-2', $fresh->access_token);
        $this->assertSame('refresh-token-2', $fresh->refresh_token);
        $raw = DB::table('calendly_connections')->where('id', $fresh->id)->first();
        $this->assertStringNotContainsString('access-token-2', $raw->access_token);
        $this->assertStringNotContainsString('refresh-token-2', $raw->refresh_token);
    }

    #[Test]
    public function a_refused_refresh_marks_the_connection_as_needing_to_be_connected_again(): void
    {
        $this->connection->update(['token_expires_at' => now()->subMinute()]);
        Http::fake([
            "{$this->calendlyAuth}/oauth/token" => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        try {
            $this->service()->syncRange(now()->subDay(), now()->addDay());
            $this->fail('A refused refresh should stop the sync.');
        } catch (RuntimeException) {
        }

        $this->assertSame(CalendlyConnection::STATUS_RECONNECT, $this->connection->fresh()->status);
    }

    /**
     * Defect: a connection made before a scope was added kept answering every
     * sync with Calendly's 403 "Insufficient scope", and nothing told the host
     * that only connecting again (granting the new scope) would fix it.
     */
    #[Test]
    public function a_token_missing_a_scope_marks_the_connection_as_needing_to_be_connected_again(): void
    {
        Http::fake([
            "{$this->calendlyApi}/scheduled_events?*" => Http::response([
                'title' => 'Insufficient scope',
                'message' => "This operation requires the scopes listed in the 'required_scopes' array.",
                'details' => ['required_scopes' => ['scheduled_events:read']],
            ], 403),
        ]);

        try {
            $this->service()->syncRange(now()->subDay(), now()->addDay());
            $this->fail('A token missing a scope should stop the sync.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('calendly.needs_reconnect'), $e->getMessage());
        }

        $this->assertSame(CalendlyConnection::STATUS_RECONNECT, $this->connection->fresh()->status);
    }

    #[Test]
    public function the_token_is_never_sent_to_a_host_other_than_calendly(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        app(CalendlyClient::class)->get('https://evil.example.test/scheduled_events/EV1');
    }
}
