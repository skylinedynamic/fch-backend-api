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
 * Connecting the company's one Calendly account (OAuth 2.0): only the host's
 * team may connect, sync or disconnect it; everyone may see whether it is on.
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
        $partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($partner);
        $this->borrowerUser = $this->makeBorrowerUser($hostPartner);
    }

    private function connectUrl(): string
    {
        $this->actingAsUser($this->host);

        return $this->getJson('/api/v1/common/calendly/connect-url')->assertOk()->json('data.url');
    }

    /** @param array $overrides stubs that take the place of the defaults for the same URL */
    private function fakeCalendlyForCallback(array $overrides = []): void
    {
        Http::fake($overrides + [
            "{$this->calendlyAuth}/oauth/token" => Http::response([
                'access_token' => 'access-token-1',
                'refresh_token' => 'refresh-token-1',
                'expires_in' => 7200,
                'token_type' => 'Bearer',
                'owner' => $this->calendlyUserUri,
                'organization' => $this->calendlyOrgUri,
            ]),
            "{$this->calendlyApi}/users/me" => Http::response(['resource' => [
                'uri' => $this->calendlyUserUri,
                'name' => 'Host Calendly',
                'email' => 'host-calendly@example.test',
                'scheduling_url' => 'https://calendly.com/host',
                'timezone' => 'America/New_York',
                'current_organization' => $this->calendlyOrgUri,
            ]]),
            "{$this->calendlyApi}/webhook_subscriptions" => Http::response(['resource' => [
                'uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1",
                'callback_url' => 'https://api.example.test/api/v1/calendly/webhook',
                'state' => 'active',
            ]], 201),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [], 'pagination' => ['next_page_token' => null]]),
        ]);
    }

    #[Test]
    public function the_host_gets_the_calendly_authorize_link(): void
    {
        $url = $this->connectUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith("{$this->calendlyAuth}/oauth/authorize?", $url);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://api.example.test/api/v1/calendly/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
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

        // Defect (2026-11-10): :write alone did not let the token read -
        // GET /scheduled_events answered 403 "Insufficient scope" - so each
        // :read is asked for as well.
        $this->assertSame([
            'availability:read',      // GET /event_type_available_times
            'event_types:read',       // GET /event_types
            'scheduled_events:read',  // GET /scheduled_events, /scheduled_events/{uuid}(/invitees)
            'scheduled_events:write', // POST /invitees, POST /scheduled_events/{uuid}/cancellation
            'users:read',             // GET /users/me
            'webhooks:read',          // GET /webhook_subscriptions
            'webhooks:write',         // POST/DELETE /webhook_subscriptions
        ], $scopes);
    }

    #[Test]
    public function only_the_hosts_team_may_connect_sync_or_disconnect(): void
    {
        foreach ([$this->partnerAdmin, $this->borrowerUser] as $user) {
            $this->actingAsUser($user);
            $this->getJson('/api/v1/common/calendly/connect-url')->assertForbidden();
            $this->postJson('/api/v1/common/calendly/sync')->assertForbidden();
            $this->deleteJson('/api/v1/common/calendly/connection')->assertForbidden();
        }
    }

    #[Test]
    public function the_callback_stores_the_account_subscribes_to_webhooks_and_returns_to_the_portal(): void
    {
        $state = $this->stateOf($this->connectUrl());
        $this->fakeCalendlyForCallback();

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=connected');

        $connection = CalendlyConnection::sole();
        $this->assertSame($this->host->id, (int) $connection->connected_by);
        $this->assertSame($this->calendlyUserUri, $connection->calendly_user_uri);
        $this->assertSame('access-token-1', $connection->access_token);
        $this->assertSame("{$this->calendlyApi}/webhook_subscriptions/WH1", $connection->webhook_subscription_uri);

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyAuth}/oauth/token"
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'AUTH-CODE'
            && $request['redirect_uri'] === 'https://api.example.test/api/v1/calendly/callback');
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyApi}/webhook_subscriptions"
            && $request['scope'] === 'user'
            && $request['user'] === $this->calendlyUserUri
            && $request['url'] === 'https://api.example.test/api/v1/calendly/webhook'
            && $request['signing_key'] === $this->calendlySigningKey
            && $request['events'] === ['invitee.created', 'invitee.canceled']);
    }

    #[Test]
    public function an_existing_webhook_for_the_same_url_is_reused(): void
    {
        $state = $this->stateOf($this->connectUrl());
        $this->fakeCalendlyForCallback([
            "{$this->calendlyApi}/webhook_subscriptions" => Http::response(['title' => 'Already Exists', 'message' => 'Hook with this url already exists'], 409),
            "{$this->calendlyApi}/webhook_subscriptions?*" => Http::response(['collection' => [[
                'uri' => "{$this->calendlyApi}/webhook_subscriptions/WH-OLD",
                'callback_url' => 'https://api.example.test/api/v1/calendly/webhook',
            ]]]),
        ]);

        $this->get('/api/v1/calendly/callback?code=AUTH-CODE&state='.urlencode($state))
            ->assertRedirect('https://partner.example.test/appointments?calendly=connected');

        $this->assertSame("{$this->calendlyApi}/webhook_subscriptions/WH-OLD", CalendlyConnection::sole()->webhook_subscription_uri);
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
        $this->assertSame(0, CalendlyConnection::count());
    }

    #[Test]
    public function everyone_sees_whether_calendly_is_on_but_never_the_tokens(): void
    {
        $this->actingAsUser($this->borrowerUser);
        $this->getJson('/api/v1/common/calendly/status')->assertOk()->assertJsonPath('data.connected', false);

        $this->connectCalendly($this->host, ['webhook_subscription_uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1"]);

        $borrowerView = $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.can_book', true);
        $this->assertStringNotContainsString('access-token-1', $borrowerView->getContent());
        $this->assertStringNotContainsString('refresh-token-1', $borrowerView->getContent());

        $this->actingAsUser($this->host);
        $this->getJson('/api/v1/common/calendly/status')->assertOk()
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.webhooks_active', true)
            ->assertJsonPath('data.email', 'host-calendly@example.test');
    }

    #[Test]
    public function disconnecting_removes_the_webhook_and_the_tokens_but_keeps_the_meetings(): void
    {
        $this->connectCalendly($this->host, ['webhook_subscription_uri' => "{$this->calendlyApi}/webhook_subscriptions/WH1"]);
        Appointment::create([
            'appointment_code' => 'APP-0001', 'subject' => 'Imported', 'appointment_date' => now()->addDay(),
            'timezone' => 'UTC', 'duration_minutes' => 30, 'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_by' => $this->host->id, 'source' => Appointment::SOURCE_CALENDLY,
            'calendly_event_uri' => "{$this->calendlyApi}/scheduled_events/EV1",
        ]);
        Http::fake([
            "{$this->calendlyApi}/webhook_subscriptions/WH1" => Http::response(null, 204),
            "{$this->calendlyAuth}/oauth/revoke" => Http::response([], 200),
        ]);
        $this->actingAsUser($this->host);

        $this->deleteJson('/api/v1/common/calendly/connection')->assertOk();

        $this->assertSame(0, CalendlyConnection::count());
        $this->assertSame(1, Appointment::count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === "{$this->calendlyApi}/webhook_subscriptions/WH1");
        Http::assertSent(fn (Request $request) => $request->url() === "{$this->calendlyAuth}/oauth/revoke");
    }

    #[Test]
    public function the_host_can_sync_on_demand(): void
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

    #[Test]
    public function syncing_without_a_connection_says_so(): void
    {
        $this->actingAsUser($this->host);

        $this->postJson('/api/v1/common/calendly/sync')->assertStatus(422);
    }

    private function stateOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }
}
