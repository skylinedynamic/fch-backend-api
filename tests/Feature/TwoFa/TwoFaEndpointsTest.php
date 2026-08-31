<?php

namespace Tests\Feature\TwoFa;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\Notification\NotificationService;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of the /api/v1/2fa/* endpoints. These sit only
 * behind auth:api (routes/common.php) — no admin/borrower/partner role gate —
 * so any authenticated user type should be able to reach them.
 */
class TwoFaEndpointsTest extends TestCase
{
    private function bearerFor(User $user): string
    {
        return $user->createToken('test-token')->accessToken;
    }

    private function realTotpCodeFor(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigits(6);
        $totp->setPeriod(30);
        $totp->setDigest('sha1');

        return $totp->now();
    }

    // ----- setup -----

    public function test_setup_totp_is_reachable_by_an_admin_user(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'totp']);

        $response->assertOk()->assertJsonPath('data.method', 'totp');
        $this->assertNotEmpty($response->json('data.secret'));
    }

    public function test_setup_is_reachable_by_a_partner_user(): void
    {
        $partner = Partner::factory()->create();
        $user = User::factory()->partner($partner)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'totp']);

        $response->assertOk();
    }

    public function test_setup_is_reachable_by_an_employee_user(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->employee($employee)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'totp']);

        $response->assertOk();
    }

    public function test_setup_is_reachable_by_a_borrower_user(): void
    {
        $borrower = Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'totp']);

        $response->assertOk();
    }

    public function test_setup_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/2fa/setup', ['method' => 'totp']);

        $response->assertStatus(401);
    }

    public function test_setup_validation_rejects_an_unsupported_method(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'carrier-pigeon']);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.method'));
    }

    public function test_setup_sms_sends_a_code_through_the_notification_service(): void
    {
        $user = User::factory()->admin()->create();
        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')->once()->andReturn(true);
        $this->app->instance(NotificationService::class, $mock);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/setup', ['method' => 'sms']);

        $response->assertOk()->assertJsonPath('data.method', 'sms');
    }

    // ----- verify -----

    public function test_verify_totp_with_correct_code_enables_two_factor(): void
    {
        $user = User::factory()->admin()->create();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(16);
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/verify', [
                'method' => 'totp',
                'code' => $this->realTotpCodeFor($secret),
            ]);

        $response->assertOk()->assertJsonPath('data.verified', true);
        $this->assertDatabaseHas('user_two_factor_settings', ['user_id' => $user->id, 'enabled' => 1]);
    }

    public function test_verify_validation_requires_method_and_code(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/verify', []);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.method'));
        $this->assertIsString($response->json('errors.code'));
    }

    /**
     * NOTABLE FINDING (documented, not fixed — see task report):
     * TwoFaController::verify() only returns the "failure" (422) branch when
     * $result['status'] === false, but TwoFaService::verify() never returns
     * status:false for any input (including wrong codes and the locked state) —
     * it always returns status:true. So an authenticated, well-formed but
     * *incorrect* code request still comes back 200 OK, with "verified: false"
     * inside the payload rather than a non-2xx status.
     */
    public function test_verify_with_an_incorrect_code_returns_200_with_verified_false(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '111111',
            'code_expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/verify', ['method' => 'sms', 'code' => '000000']);

        $response->assertOk()->assertJsonPath('data.verified', false);
    }

    // ----- disable -----

    public function test_disable_clears_two_factor_settings_for_the_authenticated_user(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/disable');

        $response->assertOk()->assertJsonPath('status', true);
        $this->assertDatabaseHas('user_two_factor_settings', ['user_id' => $user->id, 'enabled' => 0]);
    }

    // ----- resend -----

    public function test_resend_sends_a_new_code_for_sms(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'confirmed_at' => now(),
        ]);
        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')->once()->andReturn(true);
        $this->app->instance(NotificationService::class, $mock);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/resend', ['method' => 'sms']);

        $response->assertOk();
    }

    public function test_resend_validation_rejects_totp_as_a_method(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/resend', ['method' => 'totp']);

        $response->assertStatus(422);
    }

    // ----- status -----

    public function test_status_reports_current_state_for_the_authenticated_user(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->getJson('/api/v1/2fa/status');

        $response->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.method', TwoFaAuthSetting::TOTP)
            ->assertJsonPath('data.has_recovery_codes', true);
    }

    // ----- recovery codes -----

    public function test_regenerate_recovery_codes_for_the_authenticated_user(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($user))
            ->postJson('/api/v1/2fa/recovery-codes/regenerate');

        $response->assertOk();
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_regenerate_recovery_codes_for_an_explicit_user_id(): void
    {
        $actingUser = User::factory()->admin()->create();
        $targetUser = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $targetUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerFor($actingUser))
            ->postJson('/api/v1/2fa/recovery-codes/regenerate', ['user_id' => $targetUser->id]);

        $response->assertOk();
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }
}
