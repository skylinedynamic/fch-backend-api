<?php

namespace Tests\Feature\TwoFaAuth;

use App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController;
use App\Models\Partner\Partner;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * /api/v1/2fa/* (managing your own second factor) and
 * /api/v1/auth/login/2fa/confirm (the second step of a sign-in).
 */
#[CoversClass(TwoFaController::class)]
class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersonalAccessClient();
        $this->host = $this->makeHostPartner();
        $this->user = $this->makePartnerAdmin($this->host);
    }

    /** Sets up TOTP as the signed-in user and returns the secret the app shows in the QR code. */
    private function setUpTotp(): string
    {
        $this->actingAsUser($this->user);

        return $this->postJson('/api/v1/2fa/setup', ['method' => 'totp'])->json('data.secret');
    }

    private function totpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    /**
     * Enables TOTP for $this->user and returns [secret, recovery codes]. The
     * verify response does not include the codes (TwoFaVerificationResource
     * drops them), so they are fetched the way the user would: regenerate.
     */
    private function enableTotp(): array
    {
        $secret = $this->setUpTotp();
        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => $this->totpCode($secret)])->assertOk();
        $codes = $this->postJson('/api/v1/2fa/recovery-codes/regenerate')->json('data.recovery_codes');

        return [$secret, $codes];
    }

    #[Test]
    public function every_two_factor_endpoint_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/2fa/status')->assertUnauthorized();
        $this->postJson('/api/v1/2fa/setup', ['method' => 'totp'])->assertUnauthorized();
        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => '123456'])->assertUnauthorized();
        $this->postJson('/api/v1/2fa/disable')->assertUnauthorized();
        $this->postJson('/api/v1/2fa/resend', ['method' => 'email'])->assertUnauthorized();
        $this->postJson('/api/v1/2fa/recovery-codes/regenerate')->assertUnauthorized();
    }

    #[Test]
    public function a_user_who_never_set_it_up_has_two_factor_off(): void
    {
        $this->actingAsUser($this->user);

        $this->getJson('/api/v1/2fa/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.method', null)
            ->assertJsonPath('data.has_recovery_codes', false);
    }

    #[Test]
    public function totp_setup_returns_a_secret_and_qr_code_but_does_not_enable_two_factor_yet(): void
    {
        $this->actingAsUser($this->user);

        $this->postJson('/api/v1/2fa/setup', ['method' => 'totp'])
            ->assertOk()
            ->assertJsonPath('data.method', 'totp')
            ->assertJsonStructure(['data' => ['secret', 'qr_svg']]);

        $this->getJson('/api/v1/2fa/status')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.method', 'totp');
    }

    #[Test]
    public function the_secret_is_stored_encrypted(): void
    {
        $secret = $this->setUpTotp();

        $stored = TwoFaAuthSetting::where('user_id', $this->user->id)->value('secret');
        $this->assertNotSame($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
    }

    #[Test]
    public function a_correct_totp_code_enables_two_factor_with_recovery_codes_on_file(): void
    {
        $secret = $this->setUpTotp();

        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonMissingPath('data.recovery_codes');

        $this->getJson('/api/v1/2fa/status')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.has_recovery_codes', true);
    }

    /**
     * Defect: the service reported a wrong code with status=true, so the
     * controller answered 200 "2FA verified" and only data.verified said no.
     */
    #[Test]
    public function a_wrong_code_is_rejected_and_two_factor_stays_off(): void
    {
        $this->setUpTotp();

        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('errors.verified', false);

        $this->getJson('/api/v1/2fa/status')->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function five_wrong_codes_lock_verification_even_for_the_right_code(): void
    {
        $secret = $this->setUpTotp();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => '000000']);
        }

        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertStatus(422);
        $this->assertNotNull(TwoFaAuthSetting::where('user_id', $this->user->id)->value('locked_until'));
    }

    #[Test]
    public function the_method_and_code_are_validated(): void
    {
        $this->actingAsUser($this->user);

        $this->postJson('/api/v1/2fa/setup', ['method' => 'carrier-pigeon'])->assertStatus(422);
        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp'])->assertStatus(422);
        $this->postJson('/api/v1/2fa/verify', ['method' => 'totp', 'code' => '12'])->assertStatus(422);
    }

    #[Test]
    public function an_emailed_code_can_be_resent_but_an_authenticator_code_cannot(): void
    {
        $this->actingAsUser($this->user);

        $this->postJson('/api/v1/2fa/resend', ['method' => 'totp'])->assertStatus(422);
    }

    #[Test]
    public function disabling_clears_the_secret_and_recovery_codes(): void
    {
        $this->enableTotp();

        $this->postJson('/api/v1/2fa/disable')->assertOk();

        $setting = TwoFaAuthSetting::where('user_id', $this->user->id)->first();
        $this->assertFalse($setting->enabled);
        $this->assertNull($setting->secret);
        $this->assertNull($setting->recovery_codes);
    }

    #[Test]
    public function regenerating_replaces_your_recovery_codes(): void
    {
        [, $old] = $this->enableTotp();
        $this->assertCount(8, $old);

        $new = $this->postJson('/api/v1/2fa/recovery-codes/regenerate')
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes')
            ->json('data.recovery_codes');

        $this->assertEmpty(array_intersect($old, $new));
    }

    /**
     * Defect (account takeover): the endpoint accepted any user_id and returned
     * that user's fresh recovery codes, which /auth/login/2fa/confirm then
     * exchanged for a token - so any signed-in user, a borrower included,
     * could sign in as anyone without their password.
     */
    #[Test]
    public function you_cannot_regenerate_anyone_elses_recovery_codes(): void
    {
        $victim = $this->makePartnerAdmin($this->host);
        $attacker = $this->makeBorrowerUser($this->host);
        $this->actingAsUser($attacker);

        $this->postJson('/api/v1/2fa/recovery-codes/regenerate', ['user_id' => $victim->id])
            ->assertForbidden()
            ->assertJsonMissingPath('data.recovery_codes');

        $this->assertDatabaseMissing((new TwoFaAuthSetting())->getTable(), ['user_id' => $victim->id]);
    }

    #[Test]
    public function passing_your_own_user_id_still_works(): void
    {
        $this->enableTotp();

        $this->postJson('/api/v1/2fa/recovery-codes/regenerate', ['user_id' => $this->user->id])
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes');
    }

    #[Test]
    public function the_sign_in_second_step_accepts_a_current_authenticator_code(): void
    {
        [$secret] = $this->enableTotp();

        $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $this->user->id,
            'method' => 'totp',
            'code' => $this->totpCode($secret),
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    #[Test]
    public function a_recovery_code_signs_you_in_once(): void
    {
        [, $codes] = $this->enableTotp();
        $confirm = fn () => $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $this->user->id,
            'method' => 'totp',
            'code' => $codes[0],
        ]);

        $confirm()->assertOk()->assertJsonStructure(['data' => ['token']]);
        $confirm()->assertStatus(422)->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_wrong_second_step_code_gets_no_token(): void
    {
        $this->enableTotp();

        $this->postJson('/api/v1/auth/login/2fa/confirm', [
            'user_id' => $this->user->id,
            'method' => 'totp',
            'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.verified', false)
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function the_second_step_validates_its_input(): void
    {
        $this->postJson('/api/v1/auth/login/2fa/confirm', [])->assertStatus(422);
        $this->postJson('/api/v1/auth/login/2fa/confirm', ['user_id' => 999999, 'method' => 'totp', 'code' => '123456'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/login/2fa/confirm', ['user_id' => $this->user->id, 'method' => 'fax', 'code' => '123456'])
            ->assertStatus(422);
    }
}
