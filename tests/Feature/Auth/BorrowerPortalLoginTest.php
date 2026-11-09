<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\V1\Borrower\Auth\AuthController;
use App\Models\Borrower\Borrower;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * POST /api/v1/borrower/login and /borrower/logout. The reserved borrower
 * portal serves the host's borrowers and certified partners' borrowers (when
 * the partner has no borrower domain of its own); a white-label partner's
 * borrowers sign in on that partner's activated custom domain.
 */
#[CoversClass(AuthController::class)]
class BorrowerPortalLoginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/borrower/login';
    private const BORROWER_PORTAL = 'app.fch.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domains.reserved.base_reserved_partner_portal' => 'partner.fch.test',
            'domains.reserved.base_reserved_borrower_portal' => self::BORROWER_PORTAL,
        ]);
        $this->createPersonalAccessClient();
    }

    private function login(string $email, string $domain = self::BORROWER_PORTAL, string $password = 'password')
    {
        return $this->withHeader('Origin', 'https://' . $domain)
            ->postJson(self::URL, ['email' => $email, 'password' => $password]);
    }

    #[Test]
    public function a_host_borrower_signs_in_on_the_borrower_portal(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner());

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('data.user.user_type', 'Borrower')
            ->assertJsonPath('data.user.is_host', true)
            ->assertJsonStructure(['data' => ['token']]);
    }

    #[Test]
    public function a_certified_partners_borrower_signs_in_on_the_borrower_portal_when_the_partner_has_no_domain(): void
    {
        $user = $this->makeBorrowerUser($this->makeCertifiedPartner());

        $this->login($user->email)->assertOk()->assertJsonPath('data.user.is_host', false);
    }

    #[Test]
    public function a_white_label_partners_borrower_is_refused_on_the_shared_borrower_portal(): void
    {
        $user = $this->makeBorrowerUser($this->makeWhiteLabelPartner());

        $this->login($user->email)->assertUnauthorized()->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_white_label_partners_borrower_signs_in_on_the_partners_activated_borrower_domain(): void
    {
        $partner = $this->makeWhiteLabelPartner(['borrower_domain' => 'clients.acme.test', 'active_custom_url' => true]);
        $user = $this->makeBorrowerUser($partner);

        $this->login($user->email, 'clients.acme.test')->assertOk();
    }

    #[Test]
    public function a_borrower_domain_that_is_not_activated_yet_refuses_sign_in(): void
    {
        $partner = $this->makeWhiteLabelPartner(['borrower_domain' => 'clients.acme.test', 'active_custom_url' => false]);
        $user = $this->makeBorrowerUser($partner);

        $this->login($user->email, 'clients.acme.test')->assertUnauthorized();
    }

    #[Test]
    public function a_partner_account_does_not_exist_as_far_as_the_borrower_portal_is_concerned(): void
    {
        $admin = $this->makePartnerAdmin($this->makeHostPartner());

        $this->login($admin->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.invalid_login'));
    }

    #[Test]
    public function an_onboarding_borrower_can_sign_in(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner(), ['status' => Borrower::STATUS_ONBOARDING]);

        $this->login($user->email)->assertOk();
    }

    #[Test]
    public function a_suspended_borrower_is_told_so(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner(), ['status' => Borrower::STATUS_SUSPENDED]);

        $this->login($user->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.borrower_suspended'));
    }

    #[Test]
    public function an_inactive_borrower_is_told_so(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner(), ['status' => Borrower::STATUS_INACTIVE]);

        $this->login($user->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.borrower_inactive'));
    }

    /**
     * BUG-067: the password was checked last, after the account's state, so a
     * wrong password on a registered account still got "suspended", "inactive"
     * or "free plan expired" (naming the partner) - telling an outsider the
     * email is registered. A wrong password now always gets the neutral
     * message; the account's state is only told to someone with the password.
     */
    public static function accountStates(): array
    {
        return [
            'suspended' => [['status' => Borrower::STATUS_SUSPENDED], [], 'auth.borrower_suspended'],
            'inactive' => [['status' => Borrower::STATUS_INACTIVE], [], 'auth.borrower_inactive'],
            'free plan expired' => [['is_free_trail' => true, 'free_trail_end_date' => now()->subDay()->toDateString()], [], 'auth.free_plan_expired'],
            'login deactivated' => [[], ['status' => 0], 'auth.user_inactive'],
        ];
    }

    #[Test]
    #[DataProvider('accountStates')]
    public function a_wrong_password_never_reveals_the_accounts_state(array $borrower, array $user, string $stateMessage): void
    {
        $partner = $this->makeHostPartner();
        $account = $this->makeBorrowerUser($partner, $borrower, $user);

        $this->login($account->email, password: 'wrong-password')
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.invalid_login'))
            ->assertJsonPath('field', 'password');

        // With the right password, the owner is told why.
        $this->login($account->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', __($stateMessage, ['partner' => $partner->company_name ?? $partner->full_name]));
    }

    #[Test]
    public function a_wrong_password_is_reported_against_the_password_field(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner());

        $this->login($user->email, password: 'wrong-password')
            ->assertUnauthorized()
            ->assertJsonPath('field', 'password');
    }

    #[Test]
    public function logout_revokes_the_borrowers_token(): void
    {
        $user = $this->makeBorrowerUser($this->makeHostPartner());
        $token = $user->createToken('test')->accessToken;

        $this->withToken($token)->postJson('/api/v1/borrower/logout')->assertOk();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('user_id', $user->id)->value('revoked'));
    }

    #[Test]
    public function the_borrower_logout_refuses_a_partner_token(): void
    {
        $admin = $this->makePartnerAdmin($this->makeHostPartner());

        $this->withToken($admin->createToken('test')->accessToken)
            ->postJson('/api/v1/borrower/logout')
            ->assertForbidden();
    }
}
