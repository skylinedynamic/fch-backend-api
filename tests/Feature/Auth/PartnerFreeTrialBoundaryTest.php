<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsurePartnerTrialActive;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-026 (partner side): a partner's free trial is valid through its end
 * date - sign-in on the last day works - and once it has expired the partner's
 * team is stopped both at sign-in and on every request of a session already
 * open (a host shortening the trial ends it on the next request).
 *
 * R3-BUG-031: the message sends the partner's team to the administrator. It is
 * the host that sets a partner's trial, so naming the partner itself told its
 * owner to contact themselves (as the Inactive message did, BUG-113).
 */
#[CoversClass(EnsurePartnerTrialActive::class)]
#[CoversClass(Partner::class)]
class PartnerFreeTrialBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/auth/login';
    private const PARTNER_PORTAL = 'partner.fch.test';
    private const PARTNER_DOMAIN = 'cert.fch.test';

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 15:00:00');
        config([
            'domains.reserved.base_reserved_partner_portal' => self::PARTNER_PORTAL,
            'domains.reserved.base_reserved_borrower_portal' => 'app.fch.test',
        ]);
        $this->createPersonalAccessClient();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner(['partner_subdomain' => self::PARTNER_DOMAIN, 'is_free_trail' => true, 'free_trail_end_date' => '2026-11-11']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function endsOn(string $date): void
    {
        $this->partner->update(['free_trail_end_date' => $date]);
    }

    private function login(string $email)
    {
        return $this->withHeader('Origin', 'https://' . self::PARTNER_DOMAIN)
            ->postJson(self::URL, ['email' => $email, 'password' => 'password']);
    }

    private function expiredMessage(): string
    {
        return 'Your free trial has expired. Please contact administrator for assistance.';
    }

    private function assertDoesNotNameThePartner($response): void
    {
        $this->assertStringNotContainsString((string) $this->partner->company_name, (string) $response->json('message'));
    }

    #[Test]
    public function the_expired_message_sends_the_owner_to_the_administrator_not_to_its_own_company(): void
    {
        $this->partner->update(['company_name' => 'QA-TEST R3 Trial Co']);
        $this->endsOn('2026-11-10');
        $admin = $this->makePartnerAdmin($this->partner);

        $login = $this->login($admin->email)->assertUnauthorized()->assertJsonPath('message', $this->expiredMessage());
        $this->assertDoesNotNameThePartner($login);

        $this->actingAsUser($admin);
        $session = $this->getJson('/api/v1/common/refresh-user')->assertUnauthorized()->assertJsonPath('message', $this->expiredMessage());
        $this->assertDoesNotNameThePartner($session);
    }

    #[Test]
    public function the_trial_is_expired_only_after_its_end_date(): void
    {
        $this->assertFalse($this->partner->freeTrialExpired());
        $this->endsOn('2026-11-10');
        $this->assertTrue($this->partner->fresh()->freeTrialExpired());
        $this->partner->update(['is_free_trail' => false]);
        $this->assertFalse($this->partner->fresh()->freeTrialExpired());
    }

    #[Test]
    public function the_partner_signs_in_on_the_last_day(): void
    {
        $this->login($this->makePartnerAdmin($this->partner)->email)->assertOk();
    }

    #[Test]
    public function sign_in_is_refused_after_the_last_day(): void
    {
        $this->endsOn('2026-11-10');

        $this->login($this->makePartnerAdmin($this->partner)->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->expiredMessage());
    }

    #[Test]
    public function the_partners_staff_are_refused_too(): void
    {
        $this->endsOn('2026-11-10');

        $this->login($this->makeStaffUser($this->partner)->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->expiredMessage());
    }

    #[Test]
    public function an_open_session_works_on_the_last_day_and_stops_once_expired(): void
    {
        $admin = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($admin);
        $this->getJson('/api/v1/common/refresh-user')->assertOk();

        // The host shortens the trial while the partner is signed in. The same
        // token's next request loads the user afresh, as a real request does.
        $this->endsOn('2026-11-10');
        $this->actingAsUser($admin->fresh());

        $this->getJson('/api/v1/common/refresh-user')
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->expiredMessage());
    }

    #[Test]
    public function a_staff_users_open_session_stops_too(): void
    {
        $this->endsOn('2026-11-10');
        $this->actingAsUser($this->makeStaffUser($this->partner));

        $this->getJson('/api/v1/common/refresh-user')
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->expiredMessage());
    }

    #[Test]
    public function the_partners_clients_are_not_signed_out_by_it(): void
    {
        $this->endsOn('2026-11-10');
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson('/api/v1/common/refresh-user')->assertOk();
    }

    #[Test]
    public function a_partner_on_a_regular_plan_is_untouched(): void
    {
        $this->partner->update(['is_free_trail' => false, 'free_trail_end_date' => '2026-01-01']);

        $admin = $this->makePartnerAdmin($this->partner);

        $this->login($admin->email)->assertOk();
        $this->actingAsUser($admin);
        $this->getJson('/api/v1/common/refresh-user')->assertOk();
    }
}
