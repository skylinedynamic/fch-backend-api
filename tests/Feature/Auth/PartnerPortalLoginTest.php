<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * POST /api/v1/auth/login - the partner portal's sign-in, shared by the host's
 * team, white-label and certified partners and their staff. Borrowers sign in
 * through /api/v1/borrower/login instead.
 *
 * Which domain a user may sign in from is pinned here (config + Origin header)
 * rather than read from .env, so the result does not depend on the machine.
 */
#[CoversClass(AuthController::class)]
class PartnerPortalLoginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/auth/login';
    private const PARTNER_PORTAL = 'partner.fch.test';
    private const BORROWER_PORTAL = 'app.fch.test';

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domains.reserved.base_reserved_partner_portal' => self::PARTNER_PORTAL,
            'domains.reserved.base_reserved_borrower_portal' => self::BORROWER_PORTAL,
        ]);

        $this->createPersonalAccessClient();
        $this->host = $this->makeHostPartner();
    }

    private function login(string $email, string $password = 'password', string $domain = self::PARTNER_PORTAL)
    {
        return $this->withHeader('Origin', 'https://' . $domain)
            ->postJson(self::URL, ['email' => $email, 'password' => $password]);
    }

    #[Test]
    public function a_host_admin_signs_in_on_the_partner_portal_and_gets_a_token_and_their_profile(): void
    {
        $user = $this->makePartnerAdmin($this->host);

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.is_host', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'user_type', 'partner_id', 'permissions']]]);
    }

    #[Test]
    public function a_host_staff_user_signs_in_on_the_partner_portal(): void
    {
        $user = $this->makeStaffUser($this->host, ['view leads']);

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    #[Test]
    public function a_white_label_partner_signs_in_on_its_own_subdomain(): void
    {
        $partner = $this->makeWhiteLabelPartner(['partner_subdomain' => 'acme.fch.test']);
        $user = $this->makePartnerAdmin($partner);

        $this->login($user->email, domain: 'acme.fch.test')
            ->assertOk()
            ->assertJsonPath('data.user.is_host', false)
            ->assertJsonPath('data.user.partner_type', Partner::PARTNER_TYPE_WHITE_LABEL);
    }

    #[Test]
    public function a_certified_partner_signs_in_on_its_own_subdomain(): void
    {
        $partner = $this->makeCertifiedPartner(['partner_subdomain' => 'cert.fch.test']);
        $user = $this->makePartnerAdmin($partner);

        $this->login($user->email, domain: 'cert.fch.test')
            ->assertOk()
            ->assertJsonPath('data.user.partner_type', Partner::PARTNER_TYPE_CERTIFIED);
    }

    #[Test]
    public function a_non_host_partner_is_refused_on_the_hosts_reserved_partner_portal(): void
    {
        $user = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        $this->login($user->email)
            ->assertUnauthorized()
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_partner_cannot_sign_in_on_another_partners_subdomain(): void
    {
        $this->makeWhiteLabelPartner(['partner_subdomain' => 'other.fch.test']);
        $user = $this->makePartnerAdmin($this->makeWhiteLabelPartner(['partner_subdomain' => 'mine.fch.test']));

        $this->login($user->email, domain: 'other.fch.test')->assertUnauthorized();
    }

    #[Test]
    public function a_borrower_account_does_not_exist_as_far_as_the_partner_portal_is_concerned(): void
    {
        $borrower = $this->makeBorrowerUser($this->host);

        $this->login($borrower->email)
            ->assertUnauthorized()
            ->assertJsonPath('field', 'email')
            ->assertJsonPath('message', __('auth.invalid_email'));
    }

    #[Test]
    public function an_unknown_email_is_reported_against_the_email_field(): void
    {
        $this->login('nobody@example.test')
            ->assertUnauthorized()
            ->assertJsonPath('field', 'email')
            ->assertJsonPath('message', __('auth.invalid_email'));
    }

    #[Test]
    public function a_wrong_password_is_reported_against_the_password_field_with_the_attempts_left(): void
    {
        $user = $this->makePartnerAdmin($this->host);

        $this->login($user->email, 'wrong-password')
            ->assertUnauthorized()
            ->assertJsonPath('field', 'password')
            ->assertJsonPath('message', __('auth.invalid_password'))
            ->assertJson(['max_attempts' => 5, 'remaining_attempts' => 4]);
    }

    /**
     * Defect (O4): max_attempts was read straight from env, so it came back as
     * the string "5" while remaining_attempts was a number.
     */
    #[Test]
    public function the_attempt_counts_are_numbers(): void
    {
        config(['app.login_throttle_limit' => '5']);
        $user = $this->makePartnerAdmin($this->host);

        $response = $this->login($user->email, 'wrong-password');

        $this->assertSame(5, $response->json('max_attempts'));
        $this->assertSame(4, $response->json('remaining_attempts'));
    }

    #[Test]
    public function repeated_wrong_passwords_lock_the_account_out_even_for_the_right_one(): void
    {
        $user = $this->makePartnerAdmin($this->host);

        for ($i = 0; $i < 5; $i++) {
            $this->login($user->email, 'wrong-password');
        }

        $this->login($user->email)
            ->assertStatus(429)
            ->assertJsonPath('status', false);
    }

    #[Test]
    public function an_inactive_user_cannot_sign_in(): void
    {
        $user = $this->makePartnerAdmin($this->host, attributes: ['status' => User::STATUS['inactive']]);

        $this->login($user->email)
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.user_inactive'));
    }

    #[Test]
    public function a_suspended_partner_cannot_sign_in_even_on_its_own_subdomain(): void
    {
        $partner = $this->makeWhiteLabelPartner([
            'partner_subdomain' => 'sus.fch.test',
            'status' => Partner::STATUS_SUSPENDED,
        ]);
        $user = $this->makePartnerAdmin($partner);

        $this->login($user->email, domain: 'sus.fch.test')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_user_with_two_factor_enabled_gets_a_challenge_instead_of_a_token(): void
    {
        $user = $this->makePartnerAdmin($this->host);
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
        ]);

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonPath('data.two_factor_method', TwoFaAuthSetting::TOTP)
            ->assertJsonMissingPath('data.token');
    }

    /**
     * Decision 2026-10-13 (findings D6): partner and borrower login have no
     * reCAPTCHA. The optional `recaptcha` rule left on LoginRequest is gone, so
     * a stray token is ignored rather than checked against the website's
     * Site Settings secret - which would refuse a valid login.
     */
    #[Test]
    public function login_has_no_recaptcha_check(): void
    {
        AppConfiguration::set('recaptcha_secret_key', 'site-secret', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false])]);
        $user = $this->makePartnerAdmin($this->host);

        $this->withHeader('Origin', 'https://' . self::PARTNER_PORTAL)
            ->postJson(self::URL, ['email' => $user->email, 'password' => 'password', 'recaptcha' => 'stray-token'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);

        Http::assertNothingSent();
    }

    #[Test]
    public function email_and_password_are_required(): void
    {
        $this->postJson(self::URL, [])->assertStatus(422);
        $this->postJson(self::URL, ['email' => 'not-an-email', 'password' => 'password'])->assertStatus(422);
        $this->postJson(self::URL, ['email' => 'a@example.test', 'password' => '123'])->assertStatus(422);
    }
}
