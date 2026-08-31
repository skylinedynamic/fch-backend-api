<?php

namespace Tests\Unit\Services\Global\TwoFaAuth;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\Notification\NotificationService;
use App\Services\Global\TwoFaAuth\TwoFaService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use OTPHP\Exception\SecretDecodingException;
use OTPHP\TOTP;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFaServiceTest extends TestCase
{
    private function service(?NotificationService $notificationService = null): TwoFaService
    {
        return new TwoFaService($notificationService ?? \Mockery::mock(NotificationService::class));
    }

    private function actingUser(): User
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'api');

        return $user;
    }

    private function realTotpCodeFor(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigits(6);
        $totp->setPeriod(30);
        $totp->setDigest('sha1');

        return $totp->now();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ----- setup() -----

    public function test_setup_totp_generates_and_persists_an_encrypted_secret(): void
    {
        $user = $this->actingUser();

        $result = $this->service()->setup(TwoFaAuthSetting::TOTP);

        $this->assertTrue($result['status']);
        $this->assertSame('totp', $result['data']['method']);
        $this->assertNotEmpty($result['data']['secret']);
        $this->assertNotEmpty($result['data']['otpauth_uri']);
        $this->assertNotEmpty($result['data']['qr_svg']);

        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertNotNull($setting);
        $this->assertFalse($setting->enabled);
        $this->assertSame(TwoFaAuthSetting::TOTP, $setting->method);
        $this->assertSame($result['data']['secret'], Crypt::decryptString($setting->secret));
    }

    public function test_setup_sms_sends_a_code_via_notification_service_and_persists_it(): void
    {
        $user = $this->actingUser();

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')
            ->once()
            ->withArgs(fn ($u, $notification, $channel) => $u->id === $user->id && $channel === 'sms')
            ->andReturn(true);

        $result = $this->service($mock)->setup(TwoFaAuthSetting::SMS);

        $this->assertTrue($result['status']);
        $this->assertSame('sms', $result['data']['method']);
        $this->assertSame(__('auth.2fa_code_sent'), $result['message']);

        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertNotEmpty($setting->code);
        $this->assertNotNull($setting->code_expires_at);
    }

    public function test_setup_email_sends_a_code_via_notification_service(): void
    {
        $user = $this->actingUser();

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')
            ->once()
            ->withArgs(fn ($u, $notification, $channel) => $channel === 'email')
            ->andReturn(true);

        $result = $this->service($mock)->setup(TwoFaAuthSetting::EMAIL);

        $this->assertTrue($result['status']);
        $this->assertSame('email', $result['data']['method']);
    }

    /**
     * KNOWN PRODUCTION GAP (documented, not fixed — see task report):
     * setup() assigns `$setting->method = $method` and saves BEFORE the
     * switch/default validates it, and `user_two_factor_settings.method` has a DB
     * CHECK constraint restricting it to totp/sms/email. So an invalid method
     * never reaches the graceful "auth.2fa_invalid_method" response — it throws
     * an uncaught QueryException instead. This is unreachable via HTTP today
     * because SetupTwoFaRequest already validates method in [totp,sms,email],
     * but it's a latent trap for any other caller of TwoFaService::setup().
     */
    public function test_setup_with_invalid_method_throws_a_database_constraint_violation_instead_of_a_graceful_error(): void
    {
        $this->actingUser();

        $this->expectException(QueryException::class);

        $this->service()->setup('carrier-pigeon');
    }

    public function test_setup_sms_marks_failure_when_notification_service_fails_to_send(): void
    {
        $this->actingUser();

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')->once()->andReturn(false);

        $result = $this->service($mock)->setup(TwoFaAuthSetting::SMS);

        $this->assertFalse($result['status']);
        $this->assertSame(__('auth.2fa_code_send_failed'), $result['message']);
    }

    // ----- verify() -----

    public function test_verify_totp_with_correct_code_enables_2fa_and_returns_recovery_codes(): void
    {
        $user = $this->actingUser();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(16);

        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
        ]);

        $code = $this->realTotpCodeFor($secret);

        $result = $this->service()->verify(TwoFaAuthSetting::TOTP, $code);

        $this->assertTrue($result['status']);
        $this->assertTrue($result['data']['verified']);
        $this->assertCount(8, $result['data']['recovery_codes']);
        $this->assertSame(__('auth.2fa_enabled'), $result['message']);

        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertTrue($setting->enabled);
        $this->assertNotNull($setting->confirmed_at);
        $this->assertSame(0, $setting->attempts);
    }

    public function test_verify_totp_with_incorrect_code_fails_and_increments_attempts(): void
    {
        $user = $this->actingUser();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(16);

        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
        ]);

        // Deliberately wrong: correct code with the last digit rotated.
        $correct = $this->realTotpCodeFor($secret);
        $lastDigit = (int) substr($correct, -1, 1);
        $wrongDigit = ($lastDigit + 1) % 10;
        $wrongCode = substr($correct, 0, 5).$wrongDigit;

        $result = $this->service()->verify(TwoFaAuthSetting::TOTP, $wrongCode);

        $this->assertTrue($result['status']);
        $this->assertFalse($result['data']['verified']);
        $this->assertSame(4, $result['data']['remaining_attempts']);

        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertSame(1, $setting->attempts);
        $this->assertFalse($setting->enabled);
    }

    public function test_verify_sms_with_correct_code_succeeds(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '123456',
            'code_expires_at' => now()->addMinutes(5),
        ]);

        $result = $this->service()->verify(TwoFaAuthSetting::SMS, '123456');

        $this->assertTrue($result['data']['verified']);
        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertTrue($setting->enabled);
        $this->assertNull($setting->code);
        $this->assertNull($setting->code_expires_at);
    }

    public function test_verify_sms_with_expired_code_fails(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '123456',
            'code_expires_at' => now()->subMinute(),
        ]);

        $result = $this->service()->verify(TwoFaAuthSetting::SMS, '123456');

        $this->assertFalse($result['data']['verified']);
    }

    public function test_verify_locks_out_after_max_attempts(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '123456',
            'code_expires_at' => now()->addMinutes(5),
        ]);

        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            $service->verify(TwoFaAuthSetting::SMS, 'wrong-code');
        }

        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertSame(0, $setting->attempts); // reset when the lock is applied
        $this->assertNotNull($setting->locked_until);

        // Even the correct code is rejected once locked.
        $result = $service->verify(TwoFaAuthSetting::SMS, '123456');
        $this->assertFalse($result['data']['verified']);
        $this->assertNotNull($result['data']['locked_until']);
        $this->assertStringContainsString('locked', $result['message']);
    }

    public function test_verify_with_unsupported_method_fails_and_still_counts_as_a_failed_attempt(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => false,
            'method' => TwoFaAuthSetting::TOTP,
        ]);

        $result = $this->service()->verify('carrier-pigeon', '123456');

        $this->assertFalse($result['data']['verified']);
        $this->assertSame(__('auth.2fa_invalid_method'), $result['message']);

        // Note: verify() calls incrementAttempts() unconditionally on any non-verified
        // outcome, including an invalid method — there's no early return for that case.
        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertSame(1, $setting->attempts);
    }

    // ----- resend() -----

    public function test_resend_generates_a_new_code_and_notifies_for_sms(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'confirmed_at' => now(),
        ]);

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('send')->once()->andReturn(true);

        $result = $this->service($mock)->resend($user, TwoFaAuthSetting::SMS);

        $this->assertTrue($result['status']);
        $this->assertSame(__('auth.2fa_code_resent'), $result['message']);
    }

    public function test_resend_rejects_totp_as_not_applicable(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create(['user_id' => $user->id, 'method' => TwoFaAuthSetting::TOTP]);

        $result = $this->service()->resend($user, TwoFaAuthSetting::TOTP);

        $this->assertFalse($result['status']);
        $this->assertSame(__('auth.2fa_resend_not_applicable'), $result['message']);
    }

    // ----- disable() -----

    public function test_disable_clears_all_two_factor_state(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $result = $this->service()->disable($user);

        $this->assertTrue($result);
        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $this->assertFalse($setting->enabled);
        $this->assertNull($setting->method);
        $this->assertNull($setting->secret);
        $this->assertNull($setting->recovery_codes);
        $this->assertSame(0, $setting->attempts);
        $this->assertNull($setting->locked_until);
    }

    // ----- status() -----

    public function test_status_reflects_current_settings(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $result = $this->service()->status($user);

        $this->assertTrue($result['enabled']);
        $this->assertSame(TwoFaAuthSetting::TOTP, $result['method']);
        $this->assertTrue($result['has_recovery_codes']);
    }

    public function test_status_for_a_user_with_no_setting_yet_reports_disabled(): void
    {
        $user = $this->actingUser();

        $result = $this->service()->status($user);

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['method']);
        $this->assertFalse($result['has_recovery_codes']);
    }

    // ----- recovery codes -----

    public function test_regenerate_recovery_codes_replaces_existing_codes(): void
    {
        $user = $this->actingUser();
        $setting = TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);
        $originalCodes = json_decode(Crypt::decryptString($setting->recovery_codes), true);

        $result = $this->service()->regenerateRecoveryCodes($user);

        $this->assertCount(8, $result['recovery_codes']);
        $this->assertNotEquals($originalCodes, $result['recovery_codes']);
    }

    public function test_recovery_code_can_be_used_once_for_login_and_then_is_consumed(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);
        $setting = TwoFaAuthSetting::where('user_id', $user->id)->first();
        $codes = json_decode(Crypt::decryptString($setting->recovery_codes), true);
        $codeToUse = $codes[0];

        $service = $this->service();

        $first = $service->verifyRecoveryCodeForLogin($user, TwoFaAuthSetting::TOTP, $codeToUse);
        $this->assertTrue($first['status']);
        $this->assertTrue($first['data']['verified']);
        $this->assertTrue($first['data']['recovery_code_used']);

        // Using the exact same code again must fail — it was consumed.
        $second = $service->verifyRecoveryCodeForLogin($user, TwoFaAuthSetting::TOTP, $codeToUse);
        $this->assertFalse($second['status']);
        $this->assertFalse($second['data']['verified']);
    }

    // ----- verifyForLogin() -----

    public function test_verify_for_login_succeeds_with_correct_totp_code(): void
    {
        $user = $this->actingUser();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(16);
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        $result = $this->service()->verifyForLogin($user, TwoFaAuthSetting::TOTP, $this->realTotpCodeFor($secret));

        $this->assertTrue($result['status']);
        $this->assertTrue($result['data']['verified']);
    }

    public function test_verify_for_login_fails_with_incorrect_code_and_reports_remaining_attempts(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '654321',
            'code_expires_at' => now()->addMinutes(5),
            'confirmed_at' => now(),
        ]);

        $result = $this->service()->verifyForLogin($user, TwoFaAuthSetting::SMS, '000000');

        $this->assertFalse($result['status']);
        $this->assertFalse($result['data']['verified']);
        $this->assertSame(4, $result['data']['remaining_attempts']);
    }

    public function test_verify_for_login_reports_locked_state(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::factory()->locked()->create(['user_id' => $user->id, 'method' => TwoFaAuthSetting::TOTP]);

        $result = $this->service()->verifyForLogin($user, TwoFaAuthSetting::TOTP, '000000');

        $this->assertFalse($result['status']);
        $this->assertFalse($result['data']['verified']);
        $this->assertNotNull($result['data']['locked_until']);
    }

    /**
     * KNOWN PRODUCTION BUG (documented, not fixed — see task report):
     * verifyTotp() only wraps Crypt::decryptString($setting->secret) in a
     * try/catch — the subsequent $totp->verify($code) call is NOT protected.
     * If a stored (decrypted) secret is not valid base32 — e.g. this project's
     * own TwoFaAuthSettingFactory::totpEnabled() placeholder secret,
     * 'SECRETSECRETSECRETSECRET1234', which contains '1'/'8' (not part of the
     * base32 alphabet) — OTPHP throws SecretDecodingException, which propagates
     * all the way up through TwoFaController as an uncaught 500 instead of a
     * graceful "verification failed" response. Real Google2FA-generated
     * secrets are always valid base32, so this shouldn't occur in the normal
     * setup flow, but any corrupted/hand-seeded secret (this project's own
     * test factory placeholder included) trips it.
     */
    public function test_verify_totp_with_a_non_base32_stored_secret_throws_instead_of_failing_gracefully(): void
    {
        $user = $this->actingUser();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            // This is literally TwoFaAuthSettingFactory::totpEnabled()'s own
            // placeholder secret — not valid base32 ('1' and '8' are excluded
            // from the base32 alphabet).
            'secret' => Crypt::encryptString('SECRETSECRETSECRETSECRET1234'),
            'confirmed_at' => now(),
        ]);

        $this->expectException(SecretDecodingException::class);

        $this->service()->verify(TwoFaAuthSetting::TOTP, '123456');
    }
}
