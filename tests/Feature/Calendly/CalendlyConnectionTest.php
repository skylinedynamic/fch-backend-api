<?php

namespace Tests\Feature\Calendly;

use App\Http\Controllers\Api\V1\FCH\Common\Calendly\CalendlyController;
use App\Models\Appointment\Appointment;
use App\Models\Calendly\CalendlyConnection;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesCalendly;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * One Calendly per partner - the host, each WLP, each certified partner -
 * with that partner's own Calendly OAuth app keys (Client ID, Client Secret,
 * Webhook Signing Key) saved in Settings > Calendly, where the partner account
 * or the host account sees and changes them. The partner account connects and
 * disconnects it, its team syncs it, its borrowers book with it.
 */
#[CoversClass(CalendlyController::class)]
class CalendlyConnectionTest extends TestCase
{
    use FakesCalendly;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $host;

    private User $partnerAdmin;

    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->configureCalendly();
        $hostPartner = $this->makeHostPartner();
        $this->host = $this->makePartnerAdmin($hostPartner);
        $this->partnerAdmin = $this->makePartnerAdmin($this->makeCertifiedPartner());
        $this->borrowerUser = $this->makeBorrowerUser($hostPartner);
    }

    /** Connect Calendly: the Calendly sign-in link for $user's partner. */
    private function connectUrl(?User $user = null): string
    {
        $user ??= $this->host;
        if (! CalendlyConnection::where('partner_id', $user->partner_id)->first()?->hasKeys()) {
            $this->saveCalendlyKeys($user);
        }
        $this->actingAsUser($user);

        return $this->postJson('/api/v1/common/calendly/connect')->assertOk()->json('data.url');
    }

    /** @param array $overrides stubs that take the place of the defaults for the same URL */
    private function fakeCalendlyForCallback(array $overrides = [], ?string $userUri = null): void
    {
        Http::fake($overrides + [
            "{$this->calendlyAuth}/oauth/token" => Http::response([
                'access_token' => 'access-token-1',
                'refresh_token' => 'refresh-token-1',
                'expires_in' => 7200,
                'token_type' => 'Bearer',
                'owner' => $userUri ?? $this->calendlyUserUri,
                'organization' => $this->calendlyOrgUri,
            ]),
            "{$this->calendlyApi}/users/me" => Http::response(['resource' => [
                'uri' => $userUri ?? $this->calendlyUserUri,
                'name' => 'Host Calendly',
                'email' => 'host-calendly@example.test',
                'scheduling_url' => 'https://calendly.com/host',
                'timezone' => 'America/New_York',
                'current_organization' => $this->calendlyOrgUri,
            ]]),
            "{$this->calendlyApi}/webhook_subscriptions" => Http::response(['resource' => [
                'uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1",
                'state' => 'active',
            ]], 201),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
        ]);
    }

    #[Test]
    public function connect_is_one_click_through_fchs_calendly_app(): void
    {
        $url = $this->connectUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith("{$this->calendlyAuth}/oauth/authorize?", $url);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://api.example.test/api/v1/calendly/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);

        $connection = CalendlyConnection::sole();
        $this->assertSame((int) $this->host->partner_id, (int) $connection->partner_id);
        $this->assertSame(CalendlyConnection::STATUS_PENDING, $connection->status);
        // Its own webhook signing key, made by FCH - nobody types one.
        $this->assertNotEmpty($connection->webhook_signing_key);
    }

    /**
     * Defect: the authorize link asked for no scopes. Calendly's OAuth grants
     * only the scopes requested, and each endpoint needs its own - without
     * them every call after connecting is refused (permission denied).
     */
    #[Test]
    public function the_authorize_link_asks_for_every_scope_the_integration_uses(): void
    {
        parse_str((string) parse_url($this->connectUrl(), PHP_URL_QUERY), $query);

        $scopes = explode(' ', $query['scope'] ?? '');
        sort($scopes);

        $this->assertSame([
            'availability:read', 'event_types:read', 'event_types:write', 'scheduled_events:read', 'scheduled_events:write',
            'users:read', 'webhooks:read', 'webhooks:write',
        ], $scopes);
    }

    #[Test]
    public function the_callback_signs_in_with_fchs_app_and_subscribes_the_connections_own_webhook(): void
    {
        $state = $this->stateOf($this->connectUrl());
        $this->fakeCalendlyForCallback();

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=connected');

        $connection = CalendlyConnection::sole();
        $this->assertTrue($connection->isActive());
        $this->assertSame($this->host->id, (int) $connection->connected_by);
        $this->assertSame('access-token-1', $connection->access_token);

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyAuth}/oauth/token"
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'AUTH-CODE'
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret'
            && $request['redirect_uri'] === 'https://api.example.test/api/v1/calendly/callback');
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/webhook_subscriptions"
            && $request['user'] === $this->calendlyUserUri
            && $request['url'] === "https://api.example.test/api/v1/calendly/webhook/{$connection->id}"
            && $request['signing_key'] === $connection->webhook_signing_key
            && $request['events'] === ['invitee.created', 'invitee.canceled']);
    }

    #[Test]
    public function an_existing_webhook_for_the_same_url_is_reused(): void
    {
        $state = $this->stateOf($this->connectUrl());
        $id = CalendlyConnection::sole()->id;
        $this->fakeCalendlyForCallback([
            "{$this->calendlyApi}/webhook_subscriptions" => Http::response(['title' => 'Already Exists', 'message' => 'Hook with this url already exists'], 409),
            "{$this->calendlyApi}/webhook_subscriptions?*" => Http::response(['collection' => [[
                'uri' => "{$this->calendlyApi}/webhook_subscriptions/WH-OLD",
                'callback_url' => "https://api.example.test/api/v1/calendly/webhook/{$id}",
            ]]]),
        ]);

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))->assertRedirect();

        $this->assertSame("{$this->calendlyApi}/webhook_subscriptions/WH-OLD", CalendlyConnection::sole()->webhook_subscription_uri);
    }

    #[Test]
    public function each_partner_connects_its_own_calendly_separately(): void
    {
        $this->connectCalendly($this->host);
        $state = $this->stateOf($this->connectUrl($this->partnerAdmin));
        $this->fakeCalendlyForCallback([], "{$this->calendlyApi}/users/PARTNERUSER");

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=connected');

        $this->assertTrue(CalendlyConnection::where('partner_id', $this->partnerAdmin->partner_id)->sole()->isActive());
        $this->assertTrue(CalendlyConnection::where('partner_id', $this->host->partner_id)->sole()->isActive());
    }

    #[Test]
    public function borrowers_never_connect_sync_or_disconnect(): void
    {
        $this->connectCalendly($this->host);
        Http::fake();
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/common/calendly/connect')->assertForbidden();
        $this->postJson('/api/v1/common/calendly/sync')->assertForbidden();
        $this->deleteJson('/api/v1/common/calendly/connection')->assertForbidden();

        Http::assertNothingSent();
        $this->assertTrue(CalendlyConnection::sole()->isActive());
    }

    /**
     * Only the partner's own account - the host's, a WLP's, a certified
     * partner's - configures Calendly. Staff and other users under it cannot
     * connect or disconnect it; they may still sync it and book with it.
     */
    #[Test]
    public function staff_under_the_host_or_a_partner_cannot_connect_or_disconnect_calendly(): void
    {
        $hostConnection = $this->connectCalendly($this->host);
        $hostStaff = $this->makeStaffUser($this->host->partner);
        $partnerStaff = $this->makeStaffUser($this->partnerAdmin->partner);
        Http::fake([
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
        ]);

        foreach ([$hostStaff, $partnerStaff] as $staff) {
            $this->actingAsUser($staff);
            $this->postJson('/api/v1/common/calendly/connect')->assertForbidden();
            $this->deleteJson('/api/v1/common/calendly/connection')->assertForbidden();
        }

        $this->actingAsUser($hostStaff);
        $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.can_sync', true)
            ->assertJsonPath('data.can_book', true);
        $this->postJson('/api/v1/common/calendly/sync')->assertOk();

        $this->assertTrue($hostConnection->fresh()->isActive());
        $this->assertSame(1, CalendlyConnection::count());
    }

    #[Test]
    public function the_host_wlp_and_certified_partner_accounts_configure_their_own(): void
    {
        $wlpAccount = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        foreach ([$this->host, $wlpAccount, $this->partnerAdmin] as $account) {
            $this->saveCalendlyKeys($account);
            $this->getJson('/api/v1/common/calendly/status')->assertOk()->assertJsonPath('data.can_manage', true);
            $this->postJson('/api/v1/common/calendly/connect')->assertOk();
        }

        $this->assertSame(3, CalendlyConnection::count());
    }

    #[Test]
    public function a_calendly_account_already_connected_by_another_partner_is_refused(): void
    {
        $this->connectCalendly($this->host);
        $state = $this->stateOf($this->connectUrl($this->partnerAdmin));
        $this->fakeCalendlyForCallback();

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=error');

        $this->assertFalse(CalendlyConnection::where('partner_id', $this->partnerAdmin->partner_id)->sole()->isActive());
    }

    #[Test]
    public function a_forged_or_reused_state_is_refused_without_calling_calendly(): void
    {
        $state = $this->stateOf($this->connectUrl());
        $this->fakeCalendlyForCallback();

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state=forged')
            ->assertRedirect('https://partner.example.test/appointments?calendly=error');
        Http::assertNothingSent();

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))->assertRedirect();
        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=error');

        $this->assertSame(1, CalendlyConnection::count());
    }

    /**
     * Defect: a portal address configured without its scheme
     * (BASE_PARTNER_PORTAL=localhost:5174, as in local .env files) made the
     * browser treat the redirect as a path on the API and land on a 404.
     */
    #[Test]
    public function a_portal_address_without_a_scheme_still_returns_to_the_portal(): void
    {
        config(['app.frontend_partner_url' => 'localhost:5174/']);
        Http::fake();

        $this->get('/api/v1/calendly/callback?error=access_denied&state=unknown')
            ->assertRedirect('http://localhost:5174/appointments?calendly=error');
    }

    #[Test]
    public function declining_on_calendly_returns_to_the_portal_with_an_error(): void
    {
        $state = $this->stateOf($this->connectUrl());
        Http::fake();

        $this->get('/api/v1/calendly/callback?error=access_denied&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=error');

        Http::assertNothingSent();
        $this->assertFalse(CalendlyConnection::sole()->isActive());
    }

    #[Test]
    public function the_team_manages_it_and_its_borrowers_book_with_it_and_other_partners_see_their_own(): void
    {
        $this->connectCalendly($this->host, ['webhook_subscription_uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1"]);

        $this->actingAsUser($this->host);
        $teamView = $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.is_host', true)
            ->assertJsonPath('data.can_book', true)
            ->assertJsonPath('data.webhooks_active', true)
            ->assertJsonPath('data.email', 'host-calendly@example.test');
        $this->assertStringNotContainsString('access-token-1', $teamView->getContent());
        $this->assertStringNotContainsString($this->calendlySigningKey, $teamView->getContent());

        // The host's borrower books with the host's Calendly, but does not manage it.
        $this->actingAsUser($this->borrowerUser);
        $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.is_host', false)
            ->assertJsonPath('data.can_book', true)
            ->assertJsonPath('data.email', null);

        // Another partner, and its borrower, see their own partner's - not connected.
        $partnersBorrower = $this->makeBorrowerUser($this->partnerAdmin->partner);
        foreach ([$this->partnerAdmin, $partnersBorrower] as $user) {
            $this->actingAsUser($user);
            $this->getJson('/api/v1/common/calendly/status')->assertOk()
                ->assertJsonPath('data.connected', false)
                ->assertJsonPath('data.can_book', false);
        }
    }

    #[Test]
    public function disconnecting_ends_the_sign_in_but_keeps_the_meetings(): void
    {
        $connection = $this->connectCalendly($this->host, ['webhook_subscription_uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1"]);
        Appointment::create([
            'appointment_code' => 'APP-0001', 'subject' => 'Imported', 'appointment_date' => now()->addDay(),
            'timezone' => 'UTC', 'duration_minutes' => 30, 'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_by' => $this->host->id, 'source' => Appointment::SOURCE_CALENDLY,
            'calendly_event_uri' => "{$this->calendlyApi}/scheduled_events/EV1", 'calendly_connection_id' => $connection->id,
        ]);
        Http::fake([
            "{$this->calendlyApi}/webhook_subscriptions/WH1" => Http::response(null, 204),
            "{$this->calendlyAuth}/oauth/revoke" => Http::response([], 200),
        ]);
        $this->actingAsUser($this->host);

        $this->deleteJson('/api/v1/common/calendly/connection')->assertOk();

        $connection->refresh();
        $this->assertFalse($connection->isActive());
        $this->assertNull($connection->access_token);
        $this->assertSame(1, Appointment::count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === "{$this->calendlyApi}/webhook_subscriptions/WH1");
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyAuth}/oauth/revoke" && $request['client_id'] === 'test-client-id');
    }

    #[Test]
    public function one_partner_disconnecting_leaves_the_others_connected(): void
    {
        $this->connectCalendly($this->host);
        $partners = $this->connectCalendly($this->partnerAdmin, ['calendly_user_uri' => "{$this->calendlyApi}/users/PARTNERUSER"]);
        Http::fake();
        $this->actingAsUser($this->host);

        $this->deleteJson('/api/v1/common/calendly/connection')->assertOk();

        $this->assertTrue($partners->fresh()->isActive());
    }

    #[Test]
    public function the_team_can_sync_on_demand(): void
    {
        $this->connectCalendly($this->host);
        $event = $this->calendlyEvent('EV1', now()->addDays(2));
        Http::fake([
            "{$this->calendlyApi}/scheduled_events/EV1/invitees*" => Http::response(['collection' => [$this->calendlyInvitee($event, $this->borrowerUser->email, 'Jane')]]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [$event], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync')->assertOk()->assertJsonPath('data.synced', 1);
    }

    /**
     * Defect: an event type created in Calendly did not show in FCH after
     * "Sync now" - the event types are cached for 5 minutes and syncing only
     * brought in meetings, so the booking form kept saying there were none.
     */
    #[Test]
    public function syncing_brings_in_event_types_created_in_calendly_since_they_were_last_listed(): void
    {
        $this->connectCalendly($this->host);
        Http::fake([
            "{$this->calendlyApi}/event_types?*" => Http::sequence()
                ->push(['collection' => [], 'pagination' => ['next_page_token' => null]])
                ->push(['collection' => [$this->calendlyEventType()], 'pagination' => ['next_page_token' => null]]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
        ]);
        $this->actingAsUser($this->host);

        $this->getJson('/api/v1/common/calendly/event-types')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/common/calendly/sync')->assertOk();

        $this->getJson('/api/v1/common/calendly/event-types')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uri', $this->calendlyEventTypeUri);
    }

    #[Test]
    public function syncing_without_a_connection_says_so_and_calls_nothing(): void
    {
        Http::fake();
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/calendly/sync')->assertStatus(422);
        $this->getJson('/api/v1/common/calendly/event-types')->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * A partner with no Calendly app keys saved: Calendly is off and nothing
     * errors - but its account may still manage it, to enter them in Settings.
     */
    #[Test]
    public function without_the_partners_app_keys_calendly_is_off_and_nothing_is_called(): void
    {
        $this->connectCalendly($this->host, ['client_id' => null, 'client_secret' => null]);
        Http::fake();
        $this->actingAsUser($this->host);

        $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.can_book', false);
        $this->postJson('/api/v1/common/calendly/connect')->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->postJson('/api/v1/common/calendly/sync')->assertStatus(422);
        $this->artisan('calendly:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** Settings > Calendly shows the partner account all three keys, not just whether they are set. */
    #[Test]
    public function the_partner_account_sees_and_changes_its_own_keys_in_settings(): void
    {
        $this->saveCalendlyKeys($this->partnerAdmin, ['webhook_signing_key' => 'partner-signing-key']);

        $this->getJson('/api/v1/common/calendly/settings')->assertOk()
            ->assertJsonPath('data.client_id', 'test-client-id')
            ->assertJsonPath('data.client_secret', 'test-client-secret')
            ->assertJsonPath('data.webhook_signing_key', 'partner-signing-key');

        $this->postJson('/api/v1/common/calendly/settings', [
            'client_id' => 'new-client-id',
            'client_secret' => 'new-client-secret',
            'webhook_signing_key' => 'new-signing-key',
        ])->assertOk()
            ->assertJsonPath('data.client_id', 'new-client-id')
            ->assertJsonPath('data.client_secret', 'new-client-secret')
            ->assertJsonPath('data.webhook_signing_key', 'new-signing-key');
    }

    #[Test]
    public function the_host_account_sees_and_changes_any_partners_keys(): void
    {
        $this->saveCalendlyKeys($this->partnerAdmin);
        $partnerId = $this->partnerAdmin->partner_id;
        $this->actingAsUser($this->host);

        $this->getJson("/api/v1/common/calendly/settings?partner_id={$partnerId}")->assertOk()
            ->assertJsonPath('data.partner_id', (int) $partnerId)
            ->assertJsonPath('data.client_secret', 'test-client-secret');

        $this->postJson('/api/v1/common/calendly/settings', [
            'partner_id' => $partnerId,
            'client_id' => 'host-set-client-id',
            'client_secret' => 'host-set-secret',
            'webhook_signing_key' => 'host-set-signing-key',
        ])->assertOk();

        $connection = CalendlyConnection::where('partner_id', $partnerId)->sole();
        $this->assertSame('host-set-client-id', $connection->client_id);
        $this->assertSame('host-set-secret', $connection->client_secret);
        $this->assertSame('host-set-signing-key', $connection->webhook_signing_key);
        $this->assertNull(CalendlyConnection::where('partner_id', $this->host->partner_id)->first());
    }

    #[Test]
    public function staff_borrowers_and_other_partners_never_see_the_keys(): void
    {
        $this->saveCalendlyKeys($this->host);
        $wlpAccount = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        foreach ([$this->makeStaffUser($this->host->partner), $this->borrowerUser] as $user) {
            $this->actingAsUser($user);
            $this->getJson('/api/v1/common/calendly/settings')->assertForbidden();
            $this->postJson('/api/v1/common/calendly/settings', ['client_id' => 'x', 'client_secret' => 'y'])->assertForbidden();
        }

        // A non-host partner account cannot reach another partner's settings.
        $this->actingAsUser($wlpAccount);
        $this->getJson("/api/v1/common/calendly/settings?partner_id={$this->host->partner_id}")->assertForbidden();
        $this->getJson('/api/v1/common/calendly/status')->assertOk()->assertJsonMissingPath('data.client_secret');

        $this->assertSame('test-client-secret', CalendlyConnection::sole()->client_secret);
    }

    /** A new signing key while connected: the webhook is registered again, signed with it. */
    #[Test]
    public function changing_the_signing_key_while_connected_re_registers_the_webhook(): void
    {
        $connection = $this->connectCalendly($this->host, ['webhook_subscription_uri' => "{$this->calendlyApi}/webhook_subscriptions/WH-OLD"]);
        Http::fake([
            "{$this->calendlyApi}/webhook_subscriptions/WH-OLD" => Http::response(null, 204),
            "{$this->calendlyApi}/webhook_subscriptions" => Http::response(['resource' => ['uri' => "{$this->calendlyApi}/webhook_subscriptions/WH-NEW"]], 201),
        ]);
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/settings', [
            'client_id' => 'test-client-id',
            'webhook_signing_key' => 'rotated-signing-key',
        ])->assertOk()->assertJsonPath('data.webhook_signing_key', 'rotated-signing-key');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === "{$this->calendlyApi}/webhook_subscriptions/WH-OLD");
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === "{$this->calendlyApi}/webhook_subscriptions"
            && $request['signing_key'] === 'rotated-signing-key');
        $connection->refresh();
        $this->assertSame("{$this->calendlyApi}/webhook_subscriptions/WH-NEW", $connection->webhook_subscription_uri);
        $this->assertTrue($connection->isActive());
    }

    private function stateOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }
}
