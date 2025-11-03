<?php

namespace App\Services\Global\TwoFaAuth;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\Notification\NotificationService;
use App\Services\Global\Notification\Types\TwoFactorCodeNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;

class TwoFaService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Initialize 2FA setup for a given method.
     */
    public function setup($method = null): array
    {
        $user = Auth::user();
        $setting = $this->getOrCreateSetting($user);
        $setting->method = $method;
        $setting->enabled = false;
        $setting->confirmed_at = null;
        $setting->code = null;
        $setting->code_expires_at = null;
        $setting->save();

        switch ($method) {
            case TwoFaAuthSetting::TOTP:
                return $this->setupTotp($user, $setting);
            case TwoFaAuthSetting::SMS:
                return $this->setupSmsOrEmail($user, $setting, TwoFaAuthSetting::SMS);
            case TwoFaAuthSetting::EMAIL:
                return $this->setupSmsOrEmail($user, $setting, TwoFaAuthSetting::EMAIL);
            default:
                return [
                    'status' => false,
                    'message' => __('auth.2fa_invalid_method'),
                ];
        }
    }

    /**
     * Verify 2FA code for a given method.
     */
    public function verify(string $method, string $code): array
    {
        $user = Auth::user();
        $setting = $this->getOrCreateSetting($user);

        if ($this->isLocked($setting)) {
            return [
                'status' => true,
                'message' => __('auth.2fa_locked', ['seconds' => $this->lockoutTime($setting)]),
                'data' => [
                    'verified' => false,
                    'locked_until' => $setting->locked_until,
                ],
            ];
        }

        $verified = false;
        $message = __('auth.2fa_verification_failed');

        if ($method === TwoFaAuthSetting::TOTP) {
            $verified = $this->verifyTotp($user, $setting, $code);
        } elseif (in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
            $verified = $this->verifyCode($setting, $code);
        } else {
            $message = __('auth.2fa_invalid_method');
        }

        if ($verified) {
            $this->markEnabled($setting, $method);
            $this->resetAttempts($setting);
            $message = __('auth.2fa_enabled');
            return [
                'status' => true,
                'message' => $message,
                'data' => [
                    'verified' => true,
                    'recovery_codes' => $this->getDecryptedRecoveryCodes($setting),
                ],
            ];
        }

        $this->incrementAttempts($setting);

        return [
            'status' => true,
            'message' => $message,
            'data' => [
                'verified' => false,
                'remaining_attempts' => $this->remainingAttempts($setting),
            ],
        ];
    }

    /**
     * Disable 2FA for user.
     */
    public function disable(User $user): bool
    {
        try{
            $setting = $this->getOrCreateSetting($user);
            $setting->enabled = false;
            $setting->method = null;
            $setting->secret = null;
            $setting->confirmed_at = null;
            $setting->recovery_codes = null;
            $setting->code = null;
            $setting->code_expires_at = null;
            $setting->attempts = 0;
            $setting->locked_until = null;
            $setting->save();
        }catch(\Exception $e){
            Log::error(__('auth.2fa_disable_error') . ': ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Resend code for SMS/Email methods.
     */
    public function resend(User $user, string $method): array
    {
        $setting = $this->getOrCreateSetting($user);

        if (!in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
            return [
                'status' => false,
                'message' => __('auth.2fa_resend_not_applicable'),
            ];
        }
        return $this->setupSmsOrEmail($user, $setting, $method, true);
    }

    /**
     * Get current 2FA status.
     */
    public function status(User $user): array
    {
        $setting = $this->getOrCreateSetting($user);
        return [
            'enabled' => (bool) $setting->enabled,
            'method' => $setting->method,
            'confirmed_at' => $setting->confirmed_at,
            'has_recovery_codes' => !empty($setting->recovery_codes),
            'locked_until' => $setting->locked_until,
        ];
    }

    /**
     * Generate new recovery codes.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $setting = $this->getOrCreateSetting($user);
        $codes = $this->generateRecoveryCodes();
        $setting->recovery_codes = Crypt::encryptString(json_encode($codes));
        $setting->save();

        return [
            'recovery_codes' => $codes,
        ];
    }

    // ----- Internal helpers -----

    protected function getOrCreateSetting(User $user): TwoFaAuthSetting
    {
        /** @var TwoFaAuthSetting $setting */
        $setting = $user->twoFaAuthSetting()->firstOrCreate(['user_id' => $user->id]);
        return $setting;
    }

    protected function setupTotp(User $user, TwoFaAuthSetting $setting): array
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey(16);

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($user->email);
        $totp->setIssuer(config('TWO_FA_ISSUER', config('app.name')));
        $totp->setDigits((int) config('TOTP_DIGITS', 6));
        $totp->setPeriod((int) config('TOTP_PERIOD', 30));
        $totp->setDigest(config('TOTP_ALGORITHM', 'sha1'));

        $setting->secret = Crypt::encryptString($secret);
        $setting->save();

        $otpauth = $totp->getProvisioningUri();

        return [
            'status' => true,
            'message' => __('auth.2fa_totp_qr_scan'),
            'data' => [
                'method' => 'totp',
                'secret' => $secret,
                'otpauth_uri' => $otpauth,
                'qr_svg' => $this->generateQrSvg($otpauth)
            ]
        ];
    }

    protected function setupSmsOrEmail(User $user, TwoFaAuthSetting $setting, string $channel, bool $isResend = false): array
    {
        $code = $this->generateNumericCode(6);
        $expiresMinutes = (int) config('SMS_EMAIL_2FA_EXPIRY_MINUTES', 5);
        $setting->code = $code;
        $setting->code_expires_at = now()->addMinutes($expiresMinutes);
        $setting->save();

        $notification = new TwoFactorCodeNotification($code);
        $sent = $this->notificationService->send($user, $notification, $channel);

        return [
            'status' => $sent ? true : false,
            'message' => $sent
                ? ($isResend ? __('auth.2fa_code_resent') : __('auth.2fa_code_sent'))
                : __('auth.2fa_code_send_failed'),
            'data' => [
                'method' => $channel,
                'expires_in_minutes' => $expiresMinutes,
            ]
        ];
    }

    protected function verifyTotp(User $user, TwoFaAuthSetting $setting, string $code): bool
    {
        if (empty($setting->secret)) {
            return false;
        }
        try {
            $secret = Crypt::decryptString($setting->secret);
        } catch (\Throwable $e) {
            Log::error(__('auth.2fa_totp_decrypt_error') . ': ' . $e->getMessage(), ['user_id' => $user->id]);
            return false;
        }
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($user->email);
        $totp->setIssuer(config('TWO_FA_ISSUER', config('app.name')));
        $totp->setDigits((int) config('TOTP_DIGITS', 6));
        $totp->setPeriod((int) config('TOTP_PERIOD', 30));
        // Use digest instead of algorithm for OTPHP
        $totp->setDigest(config('TOTP_ALGORITHM', 'sha1'));

        return $totp->verify($code, null, 1);
    }

    protected function verifyCode(TwoFaAuthSetting $setting, string $code): bool
    {
        return !empty($setting->code)
            && $setting->code === $code
            && $setting->code_expires_at
            && now()->lessThanOrEqualTo($setting->code_expires_at);
    }

    protected function markEnabled(TwoFaAuthSetting $setting, string $method): void
    {
        $setting->enabled = true;
        $setting->method = $method;
        $setting->confirmed_at = now();
        if (empty($setting->recovery_codes)) {
            $codes = $this->generateRecoveryCodes();
            $setting->recovery_codes = Crypt::encryptString(json_encode($codes));
        }
        // Clear transient code fields for SMS/Email
        $setting->code = null;
        $setting->code_expires_at = null;
        $setting->save();
    }

    protected function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(10));
        }
        return $codes;
    }

    protected function getDecryptedRecoveryCodes(TwoFaAuthSetting $setting): array
    {
        if (empty($setting->recovery_codes)) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($setting->recovery_codes), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function verifyAndConsumeRecoveryCode(TwoFaAuthSetting $setting, string $code): bool
    {
        $input = trim($code);
        $codes = $this->getDecryptedRecoveryCodes($setting);
        if (empty($codes)) {
            return false;
        }
        foreach ($codes as $index => $stored) {
            if (trim($stored) === $input) {
                unset($codes[$index]);
                $codes = array_values($codes);
                $setting->recovery_codes = Crypt::encryptString(json_encode($codes));
                $setting->save();
                return true;
            }
        }
        return false;
    }

    protected function generateNumericCode(int $length = 6): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    protected function generateQrSvg(string $data): string
    {
        $renderer = new ImageRenderer(new RendererStyle(300), new SvgImageBackEnd());
        $writer = new Writer($renderer);
        return $writer->writeString($data);
    }

    protected function isLocked(TwoFaAuthSetting $setting): bool
    {
        return $setting->locked_until && now()->lessThanOrEqualTo($setting->locked_until);
    }

    protected function incrementAttempts(TwoFaAuthSetting $setting): void
    {
        $maxAttempts = (int) config('TWO_FA_MAX_ATTEMPTS', 5);
        $lockMinutes = (int) config('TWO_FA_LOCK_MINUTES', 5);
        $setting->attempts = (int) $setting->attempts + 1;
        if ($setting->attempts >= $maxAttempts) {
            $setting->locked_until = now()->addMinutes($lockMinutes);
            $setting->attempts = 0;
        }
        $setting->save();
    }

    protected function resetAttempts(TwoFaAuthSetting $setting): void
    {
        $setting->attempts = 0;
        $setting->locked_until = null;
        $setting->save();
    }

    protected function remainingAttempts(TwoFaAuthSetting $setting): int
    {
        $maxAttempts = (int) config('TWO_FA_MAX_ATTEMPTS', 5);
        return max(0, $maxAttempts - (int) $setting->attempts);
    }

    protected function lockoutTime(TwoFaAuthSetting $setting): int
    {
        if (!$setting->locked_until) {
            return 0;
        }
        
        return max(0, now()->diffInSeconds($setting->locked_until, false));
    }

    public function verifyForLogin(User $user, string $method, string $code): array
    {
        $setting = $this->getOrCreateSetting($user);

        if ($this->isLocked($setting)) {
            return [
                'status' => false,
                'message' => __('auth.2fa_locked', ['seconds' => $this->lockoutTime($setting)]),
                'data' => [
                    'verified' => false,
                    'locked_until' => $setting->locked_until,
                    'remaining_attempts' => $this->remainingAttempts($setting),
                ],
            ];
        }

        $verified = false;
        $message = __('auth.2fa_verification_failed');

        if ($method === TwoFaAuthSetting::TOTP) {
            $verified = $this->verifyTotp($user, $setting, $code);
        } elseif (in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
            $verified = $this->verifyCode($setting, $code);
        } else {
            $message = __('auth.2fa_invalid_method');
        }

        if ($verified) {
            // Successful login verification: reset attempts and clear transient code for SMS/Email
            $this->resetAttempts($setting);
            if (in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
                $setting->code = null;
                $setting->code_expires_at = null;
                $setting->save();
            }

            return [
                'status' => true,
                'message' => __('auth.2fa_verified'),
                'data' => [
                    'verified' => true,
                ],
            ];
        }

        $this->incrementAttempts($setting);

        return [
            'status' => false,
            'message' => $message,
            'data' => [
                'verified' => false,
                'remaining_attempts' => $this->remainingAttempts($setting),
            ],
        ];
    }

    public function verifyRecoveryCodeForLogin(User $user, string $method, string $code): array
    {
        $setting = $this->getOrCreateSetting($user);

        $verified = $this->verifyAndConsumeRecoveryCode($setting, $code);

        if ($verified) {
            // reset attempts and clear transient code for SMS/Email upon success
            $this->resetAttempts($setting);
            if (in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
                $setting->code = null;
                $setting->code_expires_at = null;
                $setting->save();
            }

            return [
                'status' => true,
                'message' => __('auth.2fa_verified'),
                'data' => [
                    'verified' => true,
                    'recovery_code_used' => true,
                ],
            ];
        }

        return [
            'status' => false,
            'message' => __('auth.2fa_verification_failed'),
            'data' => [
                'verified' => false,
            ],
        ];
    }
}