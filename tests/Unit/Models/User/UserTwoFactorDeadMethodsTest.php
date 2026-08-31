<?php

namespace Tests\Unit\Models\User;

use App\Models\User\User;
use Tests\TestCase;

/**
 * KNOWN PRODUCTION BUG (documented, not fixed — see task report):
 *
 * App\Models\User\User defines a family of 2FA "convenience" methods
 * (generateTwoFactorSecret(), getTwoFactorSecret(), getTotpInstance(),
 * getTwoFactorQrCodeUrl(), verifyTwoFactorCode(), generateRecoveryCodes(),
 * verifyRecoveryCode(), enableTwoFactorAuthentication(),
 * disableTwoFactorAuthentication(), incrementTwoFactorAttempts(),
 * resetTwoFactorAttempts(), generateTwoFactorCode()) that call methods which do
 * NOT exist on App\Models\TwoFaAuth\TwoFaAuthSetting (e.g. ->generateSecret(),
 * ->decrypted_secret, ->generateRecoveryCodes(), ->verifyRecoveryCode(),
 * ->disable(), ->incrementAttempts(), ->resetAttempts(), ->generateCode()).
 *
 * Nothing in app/ calls these User methods — the real 2FA flow goes entirely
 * through App\Services\Global\TwoFaAuth\TwoFaService, which does not use them.
 * They are unreachable dead code that fatals if ever invoked. This is the one
 * intentional regression test documenting that, per the task instructions —
 * it is SUPPOSED to fail/throw, and must not be "fixed."
 */
class UserTwoFactorDeadMethodsTest extends TestCase
{
    public function test_enable_two_factor_authentication_throws_because_two_fa_auth_setting_has_no_generate_secret_method(): void
    {
        $user = User::factory()->admin()->create();

        $this->expectException(\Throwable::class);

        // TwoFaAuthSetting::generateSecret() does not exist — this fatals.
        $user->enableTwoFactorAuthentication();
    }
}
