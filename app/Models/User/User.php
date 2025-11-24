<?php

namespace App\Models\User;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use OTPHP\TOTP;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Passport\Contracts\OAuthenticatable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Services\Global\Notification\NotificationService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Services\Global\Notification\Types\PasswordResetNotification as PasswordResetNotificationType;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * Spatie Permission guard to use for this model.
     */
    protected string $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'phone_verified_at',
        'email_verified_at',
        'user_type',
        'status',
        'password',
        'partner_id',
        'entity_type',
        'entity_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone_verified_at' => 'datetime',
        ];
    }

    public const STATUS = [
        'active' => 'active',
        'inactive' => 'inactive',
    ];

    public const GENDER = [
        'male' => 'male',
        'female' => 'female',
        'other' => 'other',
    ];

    public const ADMIN = 'Admin';
    public const PARTNER = 'Partner';
    public const BORROWER = 'Borrower';

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $notificationService = app(NotificationService::class);
        $notification = new PasswordResetNotificationType($token);
        $notificationService->sendEmail($this, $notification);
    }

    /**
     * Get the user's two-factor authentication settings.
     */
    public function twoFactorSetting(): HasOne
    {
        return $this->hasOne(TwoFaAuthSetting::class);
    }

    /**
     * Get or create the user's two-factor authentication settings.
     */
    public function getOrCreateTwoFactorSetting(): TwoFaAuthSetting
    {
        return $this->twoFactorSetting()->firstOrCreate(['user_id' => $this->id]);
    }

    /**
     * Generate a new TOTP secret for the user.
     */
    public function generateTwoFactorSecret(): string
    {
        $twoFactorSetting = $this->getOrCreateTwoFactorSetting();
        return $twoFactorSetting->generateSecret();
    }

    /**
     * Get the decrypted TOTP secret.
     */
    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSetting?->decrypted_secret;
    }

    /**
     * Get the TOTP instance for this user.
     */
    public function getTotpInstance(): ?TOTP
    {
        $secret = $this->getTwoFactorSecret();
        if (!$secret) {
            return null;
        }

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($this->email);
        $totp->setIssuer(config('TWO_FA_ISSUER', config('app.name')));
        $totp->setDigits(config('TOTP_DIGITS', 6));
        $totp->setPeriod(config('TOTP_PERIOD', 30));
        $totp->setDigest(config('TOTP_ALGORITHM', 'sha1'));

        return $totp;
    }

    /**
     * Get the QR code URL for TOTP setup.
     */
    public function getTwoFactorQrCodeUrl(): ?string
    {
        $totp = $this->getTotpInstance();
        return $totp ? $totp->getProvisioningUri() : null;
    }

    /**
     * Verify a TOTP code.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        $twoFactorSetting = $this->twoFactorSetting;
        if (!$twoFactorSetting) {
            return false;
        }

        if ($twoFactorSetting->method === TwoFaAuthSetting::TOTP) {
            $totp = $this->getTotpInstance();
            return $totp ? $totp->verify($code, null, 1) : false;
        }

        // For SMS/Email verification
        if (in_array($twoFactorSetting->method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
            return $twoFactorSetting->code === $code && 
                   !$twoFactorSetting->isCodeExpired();
        }

        return false;
    }

    /**
     * Generate recovery codes for the user.
     */
    public function generateRecoveryCodes(): array
    {
        $twoFactorSetting = $this->getOrCreateTwoFactorSetting();
        return $twoFactorSetting->generateRecoveryCodes();
    }

    /**
     * Verify a recovery code.
     */
    public function verifyRecoveryCode(string $code): bool
    {
        $twoFactorSetting = $this->twoFactorSetting;
        return $twoFactorSetting ? $twoFactorSetting->verifyRecoveryCode($code) : false;
    }

    /**
     * Enable two-factor authentication.
     */
    public function enableTwoFactorAuthentication(string $method = TwoFaAuthSetting::TOTP): void
    {
        $twoFactorSetting = $this->getOrCreateTwoFactorSetting();
        $twoFactorSetting->enabled = true;
        $twoFactorSetting->method = $method;
        $twoFactorSetting->confirmed_at = now();
        
        if ($method === TwoFaAuthSetting::TOTP && !$twoFactorSetting->secret) {
            $twoFactorSetting->generateSecret();
        }
        
        if (!$twoFactorSetting->recovery_codes) {
            $twoFactorSetting->generateRecoveryCodes();
        }
        
        $twoFactorSetting->save();
    }

    /**
     * Disable two-factor authentication.
     */
    public function disableTwoFactorAuthentication(): void
    {
        $twoFactorSetting = $this->twoFactorSetting;
        if ($twoFactorSetting) {
            $twoFactorSetting->disable();
        }
    }

    /**
     * Check if user has two-factor authentication enabled.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFaAuthSetting?->isEnabled() ?? false;
    }

    /**
     * Check if user has active two-factor authentication (enabled and not locked).
     */
    public function hasTwoFactorActive(): bool
    {
        return $this->twoFaAuthSetting?->isActive() ?? false;
    }

    /**
     * Check if user is locked out from 2FA attempts.
     */
    public function isTwoFactorLocked(): bool
    {
        return $this->twoFaAuthSetting?->isLocked() ?? false;
    }

    /**
     * Get the user's enabled 2FA method.
     */
    public function getTwoFactorMethod(): ?string
    {
        return $this->twoFaAuthSetting?->getEnabledMethod();
    }

    /**
     * Check if user has a specific 2FA method enabled.
     */
    public function hasTwoFactorMethod(string $method): bool
    {
        return $this->twoFaAuthSetting?->hasMethod($method) ?? false;
    }

    /**
     * Check if user has TOTP enabled.
     */
    public function hasTotpEnabled(): bool
    {
        return $this->twoFaAuthSetting?->hasTotpEnabled() ?? false;
    }

    /**
     * Check if user has SMS 2FA enabled.
     */
    public function hasSmsEnabled(): bool
    {
        return $this->twoFaAuthSetting?->hasSmsEnabled() ?? false;
    }

    /**
     * Check if user has Email 2FA enabled.
     */
    public function hasEmailTwoFactorEnabled(): bool
    {
        return $this->twoFaAuthSetting?->hasEmailEnabled() ?? false;
    }

    /**
     * Check if user needs to complete 2FA setup.
     */
    public function needsTwoFactorSetup(): bool
    {
        return $this->twoFaAuthSetting?->needsSetup() ?? true;
    }

    /**
     * Check if user can attempt 2FA verification.
     */
    public function canAttemptTwoFactorVerification(): bool
    {
        return $this->twoFaAuthSetting?->canAttemptVerification() ?? false;
    }

    /**
     * Get remaining 2FA lockout time in seconds.
     */
    public function getTwoFactorLockoutTimeRemaining(): int
    {
        return $this->twoFaAuthSetting?->getLockoutTimeRemaining() ?? 0;
    }

    /**
     * Increment 2FA failed attempts.
     */
    public function incrementTwoFactorAttempts(): void
    {
        $twoFactorSetting = $this->twoFactorSetting;
        if ($twoFactorSetting) {
            $twoFactorSetting->incrementAttempts();
        }
    }

    /**
     * Reset 2FA attempts.
     */
    public function resetTwoFactorAttempts(): void
    {
        $twoFactorSetting = $this->twoFactorSetting;
        if ($twoFactorSetting) {
            $twoFactorSetting->resetAttempts();
        }
    }

    /**
     * Generate and store a 2FA code for SMS/Email verification.
     */
    public function generateTwoFactorCode(): string
    {
        $twoFactorSetting = $this->getOrCreateTwoFactorSetting();
        return $twoFactorSetting->generateCode();
    }

    /**
     * Get the user's 2FA method.
     */
    public function getTwoFactorMethodAttribute(): ?string
    {
        return $this->twoFactorSetting?->method;
    }

    /**
     * Get the user's 2FA confirmation status.
     */
    public function getTwoFactorConfirmedAtAttribute(): ?string
    {
        return $this->twoFactorSetting?->confirmed_at;
    }

    /**
     * Get the user's 2FA code.
     */
    public function getTwoFactorCodeAttribute(): ?string
    {
        return $this->twoFactorSetting?->code;
    }

    /**
     * Get the user's 2FA code expiration.
     */
    public function getTwoFactorCodeExpiresAtAttribute(): ?string
    {
        return $this->twoFactorSetting?->code_expires_at;
    }

    /**
     * Get the user's 2FA attempts.
     */
    public function getTwoFactorAttemptsAttribute(): int
    {
        return $this->twoFactorSetting?->attempts ?? 0;
    }

    /**
     * Get the user's 2FA locked until timestamp.
     */
    public function getTwoFactorLockedUntilAttribute(): ?string
    {
        return $this->twoFactorSetting?->locked_until;
    }

    /**
     * Get the user's 2FA recovery codes.
     */
    public function getTwoFactorRecoveryCodesAttribute(): ?array
    {
        return $this->twoFactorSetting?->decrypted_recovery_codes;
    }

    /**
     * Get the user's Two-Factor settings.
     */
    public function twoFaAuthSetting(): HasOne
    {
        return $this->hasOne(TwoFaAuthSetting::class);
    }

    /**
     * Get the user's partner profile.
     */
    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id', 'id');
    }

    /**
     * Polymorphic relation to the associated entity model.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * Accessor: human-readable entity name (partner/borrower/employee).
     */
    public function getEntityNameAttribute(): ?string
    {
        if (!$this->entity_type) {
            return null;
        }

        $map = [
            Partner::class => 'Partner',
            Borrower::class => 'Borrower',
            Employee::class => 'Employee',
        ];

        return $map[$this->entity_type] ?? strtolower(class_basename($this->entity_type));
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")
            ->orWhere('phone_number', 'like', "%{$search}%");
        });
    }

    public function getRoleNamesListAttribute(): string
    {
        return $this->roles->pluck('name')->implode(', ');
    }

    public function getFirstRoleAttribute()
    {
        return $this->roles->first()?->name;
    }
    
}
