<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * POST /api/v1/auth/forgot, /auth/reset and /auth/logout. Forgot and reset run
 * the same portal-isolation check as login, so a partner cannot reset a
 * password from a domain they could not sign in on.
 */
#[CoversClass(AuthController::class)]
class PasswordResetAndLogoutTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PARTNER_PORTAL = 'partner.fch.test';

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        config(['domains.reserved.base_reserved_partner_portal' => self::PARTNER_PORTAL]);
        $this->host = $this->makeHostPartner();
    }

    private function fromPartnerPortal(): static
    {
        return $this->withHeader('Origin', 'https://' . self::PARTNER_PORTAL);
    }

    /**
     * BUG-068: forgot password answered an unknown email with 400 "We can't
     * find a user with that email address", another portal's user with 403,
     * and a known user with 200 - so anyone could check which emails have an
     * account, and on which portal. Every email now gets the same answer; the
     * link is only sent where it should be.
     */
    private function assertNeutral(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', __('auth.password_reset_link_maybe_sent'));
    }

    #[Test]
    public function forgot_password_issues_a_reset_token_for_a_known_user(): void
    {
        $user = $this->makePartnerAdmin($this->host);

        $this->assertNeutral($this->fromPartnerPortal()->postJson('/api/v1/auth/forgot', ['email' => $user->email]));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function an_unknown_email_gets_the_same_answer_and_nothing_is_issued(): void
    {
        $this->assertNeutral($this->fromPartnerPortal()->postJson('/api/v1/auth/forgot', ['email' => 'nobody@example.test']));

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[Test]
    public function a_user_of_another_portal_gets_the_same_answer_and_nothing_is_issued(): void
    {
        $user = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        $this->assertNeutral($this->fromPartnerPortal()->postJson('/api/v1/auth/forgot', ['email' => $user->email]));

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[Test]
    public function asking_again_too_soon_gets_the_same_answer_too(): void
    {
        // Laravel's "Please wait before retrying" comes only for a real
        // account, so it would give the account away as well.
        $user = $this->makePartnerAdmin($this->host);
        $this->fromPartnerPortal()->postJson('/api/v1/auth/forgot', ['email' => $user->email]);

        $this->assertNeutral($this->fromPartnerPortal()->postJson('/api/v1/auth/forgot', ['email' => $user->email]));
    }

    #[Test]
    public function forgot_password_needs_a_valid_email(): void
    {
        $this->postJson('/api/v1/auth/forgot', ['email' => 'nope'])->assertStatus(422);
    }

    #[Test]
    public function a_valid_reset_token_sets_the_new_password(): void
    {
        $user = $this->makePartnerAdmin($this->host);
        $token = Password::createToken($user);

        $this->fromPartnerPortal()
            ->postJson('/api/v1/auth/reset', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'NewPassw0rd',
                'password_confirmation' => 'NewPassw0rd',
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertTrue(Hash::check('NewPassw0rd', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[Test]
    public function a_wrong_reset_token_leaves_the_password_alone(): void
    {
        $user = $this->makePartnerAdmin($this->host);
        Password::createToken($user);

        $this->fromPartnerPortal()
            ->postJson('/api/v1/auth/reset', [
                'token' => 'not-the-token',
                'email' => $user->email,
                'password' => 'NewPassw0rd',
                'password_confirmation' => 'NewPassw0rd',
            ])
            ->assertStatus(400);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function a_new_password_must_be_eight_characters_with_mixed_case_and_a_number_and_confirmed(): void
    {
        $user = $this->makePartnerAdmin($this->host);
        $token = Password::createToken($user);
        $reset = fn (array $passwords) => $this->fromPartnerPortal()->postJson('/api/v1/auth/reset', array_merge([
            'token' => $token,
            'email' => $user->email,
        ], $passwords));

        $reset(['password' => 'alllowercase1', 'password_confirmation' => 'alllowercase1'])->assertStatus(422);
        $reset(['password' => 'NoNumbersHere', 'password_confirmation' => 'NoNumbersHere'])->assertStatus(422);
        $reset(['password' => 'Sh0rt', 'password_confirmation' => 'Sh0rt'])->assertStatus(422);
        $reset(['password' => 'NewPassw0rd', 'password_confirmation' => 'Different1A'])->assertStatus(422);
    }

    #[Test]
    public function logout_revokes_the_token_it_was_called_with(): void
    {
        $this->createPersonalAccessClient();
        $user = $this->makePartnerAdmin($this->host);
        $token = $user->createToken('test')->accessToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', __('auth.logged_out'));

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('user_id', $user->id)->value('revoked'));
    }

    #[Test]
    public function logout_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
