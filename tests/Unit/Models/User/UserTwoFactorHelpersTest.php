<?php

namespace Tests\Unit\Models\User;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Tests\TestCase;

/**
 * Covers only the REAL, working User 2FA convenience methods — the ones that
 * simply delegate to real TwoFaAuthSetting methods via the `twoFaAuthSetting`
 * relation: hasTwoFactorEnabled(), hasTwoFactorActive(), isTwoFactorLocked(),
 * getTwoFactorMethod(), hasTwoFactorMethod(), hasTotpEnabled(), hasSmsEnabled(),
 * hasEmailTwoFactorEnabled(), needsTwoFactorSetup(), canAttemptTwoFactorVerification(),
 * getTwoFactorLockoutTimeRemaining().
 *
 * See UserTwoFactorDeadMethodsTest for the separate, deliberately-broken
 * convenience methods that are NOT covered here.
 */
class UserTwoFactorHelpersTest extends TestCase
{
    public function test_user_with_no_two_factor_setting_reports_safe_defaults(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertFalse($user->hasTwoFactorActive());
        $this->assertFalse($user->isTwoFactorLocked());
        $this->assertNull($user->getTwoFactorMethod());
        $this->assertFalse($user->hasTwoFactorMethod(TwoFaAuthSetting::TOTP));
        $this->assertFalse($user->hasTotpEnabled());
        $this->assertFalse($user->hasSmsEnabled());
        $this->assertFalse($user->hasEmailTwoFactorEnabled());
        $this->assertTrue($user->needsTwoFactorSetup());
        $this->assertFalse($user->canAttemptTwoFactorVerification());
        $this->assertSame(0, $user->getTwoFactorLockoutTimeRemaining());
    }

    public function test_user_with_totp_enabled_reports_active_state(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);
        $user->refresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertTrue($user->hasTwoFactorActive());
        $this->assertFalse($user->isTwoFactorLocked());
        $this->assertSame(TwoFaAuthSetting::TOTP, $user->getTwoFactorMethod());
        $this->assertTrue($user->hasTwoFactorMethod(TwoFaAuthSetting::TOTP));
        $this->assertTrue($user->hasTotpEnabled());
        $this->assertFalse($user->hasSmsEnabled());
        $this->assertFalse($user->hasEmailTwoFactorEnabled());
        $this->assertFalse($user->needsTwoFactorSetup());
        $this->assertTrue($user->canAttemptTwoFactorVerification());
    }

    public function test_user_with_locked_two_factor_setting_reports_locked_and_cannot_attempt(): void
    {
        $user = User::factory()->admin()->create();
        TwoFaAuthSetting::factory()->totpEnabled()->locked()->create(['user_id' => $user->id]);
        $user->refresh();

        $this->assertTrue($user->isTwoFactorLocked());
        $this->assertFalse($user->canAttemptTwoFactorVerification());
        $this->assertGreaterThan(0, $user->getTwoFactorLockoutTimeRemaining());
        // Locked does not mean disabled — the method is still considered "enabled".
        $this->assertTrue($user->hasTwoFactorEnabled());
        // ...but "active" requires enabled AND not locked.
        $this->assertFalse($user->hasTwoFactorActive());
    }

    public function test_sms_and_email_method_helpers_reflect_the_confirmed_method(): void
    {
        $smsUser = User::factory()->admin()->create();
        TwoFaAuthSetting::create([
            'user_id' => $smsUser->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'confirmed_at' => now(),
        ]);
        $smsUser->refresh();
        $this->assertTrue($smsUser->hasSmsEnabled());
        $this->assertFalse($smsUser->hasTotpEnabled());
        $this->assertFalse($smsUser->hasEmailTwoFactorEnabled());

        $emailUser = User::factory()->admin()->create();
        TwoFaAuthSetting::create([
            'user_id' => $emailUser->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::EMAIL,
            'confirmed_at' => now(),
        ]);
        $emailUser->refresh();
        $this->assertTrue($emailUser->hasEmailTwoFactorEnabled());
        $this->assertFalse($emailUser->hasSmsEnabled());
    }

    public function test_unconfirmed_setting_is_not_considered_enabled(): void
    {
        $user = User::factory()->admin()->create();
        // enabled=true but confirmed_at is null — TwoFaAuthSetting::isEnabled()
        // requires enabled AND confirmed_at AND method to all be set.
        TwoFaAuthSetting::create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            'confirmed_at' => null,
        ]);
        $user->refresh();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertTrue($user->needsTwoFactorSetup());
    }
}
