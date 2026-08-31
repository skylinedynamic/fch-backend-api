<?php

namespace Tests\Feature\TwoFa;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of POST /api/v1/auth/login/2fa/confirm
 * (App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController::login_confirm()).
 * Public route — the user is identified by user_id in the payload, not a
 * bearer token, since the caller hasn't received one yet at this stage.
 */
class LoginTwoFaConfirmTest extends TestCase
{
    private function realTotpCodeFor(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigits(6);
        $totp->setPeriod(30);
        $totp->setDigest('sha1');

        return $totp->now();
    }

    public function test_confirms_with_a_correct_totp_code_and_issues_a_token(): void
    {
        $user = User::factory()->admin()->create();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(16);
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $user->id,
            'method' => 'totp',
            'code' => $this->realTotpCodeFor($secret),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.id', $user->id);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_rejects_an_incorrect_code(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'code' => '654321',
            'code_expires_at' => now()->addMinutes(5),
            'confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $user->id,
            'method' => 'sms',
            'code' => '000000',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertArrayNotHasKey('token', $response->json('data') ?? []);
    }

    public function test_confirms_with_a_valid_recovery_code_and_consumes_it(): void
    {
        $user = User::factory()->admin()->create();
        // Use a real Google2FA-generated (valid base32) secret rather than the
        // factory's placeholder 'SECRETSECRETSECRETSECRET1234' — the second
        // request below falls through to a real verifyTotp() decode attempt
        // against it once the recovery code is already consumed, and OTPHP
        // throws SecretDecodingException on non-base32 secrets (see
        // TwoFaServiceTest for a dedicated regression test of that gap).
        $secret = (new Google2FA)->generateSecretKey(16);
        $codes = ['ABCDEFGHIJ', 'KLMNOPQRST'];
        $setting = TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
            'recovery_codes' => Crypt::encryptString(json_encode($codes)),
        ]);
        $recoveryCode = $codes[0];

        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $user->id,
            'method' => 'totp',
            'code' => $recoveryCode,
        ]);

        $response->assertOk()->assertJsonPath('status', true);
        $this->assertNotEmpty($response->json('data.token'));

        // The same recovery code cannot be reused.
        $second = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $user->id,
            'method' => 'totp',
            'code' => $recoveryCode,
        ]);
        $second->assertStatus(422);
    }

    public function test_returns_404_when_user_id_does_not_correspond_to_a_real_user(): void
    {
        // user_id must pass LoginTwoFaConfirmRequest's exists:users,id rule to
        // even get this far, so use a real user then delete them to reach the
        // controller's own "user not found" branch. (SoftDeletes on User means
        // the row still technically exists for the `exists` rule.)
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);
        $userId = $user->id;
        $user->delete();

        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $userId,
            'method' => 'totp',
            'code' => '000000',
        ]);

        $response->assertStatus(404);
    }

    public function test_validation_requires_user_id_method_and_code(): void
    {
        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', []);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertIsString($errors['user_id']);
        $this->assertIsString($errors['method']);
        $this->assertIsString($errors['code']);
    }

    public function test_validation_rejects_a_non_existent_user_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => 999999,
            'method' => 'totp',
            'code' => '123456',
        ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.user_id'));
    }

    public function test_validation_rejects_an_unsupported_method(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $user->id,
            'method' => 'carrier-pigeon',
            'code' => '123456',
        ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.method'));
    }
}
