<?php

namespace App\Models\User;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Services\Global\Notification\NotificationService;
use App\Services\Global\Notification\Types\PasswordResetNotification as PasswordResetNotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use OTPHP\TOTP;
use Spatie\Permission\Traits\HasRoles;

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
        'is_paid',
        'start_date',
        'end_date',
        'is_refresh',
        'payment_status',
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
            'is_paid' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
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
    public const USER = 'User';
    public const BORROWER = 'Borrower';
    public const EMPLOYEE = 'Employee';

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
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * Get the user's borrower profile.
     */
    public function borrower()
    {
        return $this->belongsTo(Borrower::class, 'entity_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'entity_id');
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
                ->orWhere('phone_number', 'like', "%{$search}%")
                ->orWhere('entity_type', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhere('end_date', 'like', "%{$search}%")
                ->orWhereHas('roles', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
        });
    }

    public function getRoleNamesListAttribute(): string
    {
        return $this->roles->pluck('name')->implode(', ');
    }

    public function getRoleIdsListAttribute(): string
    {
        return $this->roles->pluck('id')->implode(', ');
    }

    public function getFirstRoleAttribute()
    {
        return $this->roles->first()?->name;
    }

    /**
     * Get the invoices for the user.
     */
    public function invoices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\Invoice\Invoice::class, 'entity');
    }

    /**
     * Get the subscriptions for the user.
     */
    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Subscription::class, 'entity');
    }

    /**
     * Check if the user is deletable based on invoice status.
     * Checks direct and related entity invoices.
     */
    public function isDeletable(): bool
    {
        $targetType = $this->entity_type ?: self::class;
        $targetId = $this->entity_id ?: $this->id;

        // Check for Paid or Successful invoices linked to this user or their entity
        return !Invoice::where('entity_type', $targetType)
            ->where('entity_id', $targetId)
            ->whereIn('status', [Invoice::STATUS_PAID])
            ->exists();
    }

    public static $isCascading = false;

    /**
     * Delete user and all relations ONLY if no paid invoices exist.
     */
    public function safeDeleteWithRelations(): bool
    {
        if (!$this->isDeletable()) {
            throw new \Exception("This record cannot be deleted. Please mark it as Suspended or Inactive instead.");
        }

        DB::beginTransaction();
        try {
            $isAlreadyCascading = self::$isCascading;
            self::$isCascading = true;

            // 1. If NOT already cascading, call entity safeDelete
            // This prevents infinite loops while still ensuring the primary side is deleted
            if (!$isAlreadyCascading) {
                if ($this->entity_type && $this->entity_id) {
                    $entity = $this->entity;
                    if ($entity) {
                        if (method_exists($entity, 'safeDeleteWithRelations')) {
                            $entity->safeDeleteWithRelations();
                        } elseif ($this->entity_type === Employee::class) {
                            $entity->delete();
                        }
                    }
                }
            }

            // 2. Delete Subscriptions & Phases (Direct or via Entity)
            // This ensures we clean up the entity's financial records if we are its face
            $targetType = $this->entity_type ?: self::class;
            $targetId = $this->entity_id ?: $this->id;

            Subscription::where('entity_type', $targetType)
                ->where('entity_id', $targetId)
                ->get()
                ->each(function ($sub) {
                    $sub->allPhases()->delete();
                    $sub->delete();
                });

            // 3. Delete Invoices (Unpaid only - double checked for safety)
            Invoice::where('entity_type', $targetType)
                ->where('entity_id', $targetId)
                ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS])
                ->delete();

            // 4. Detach Roles & Permissions
            $this->roles()->detach();
            $this->permissions()->detach();

            // 5. Finally Delete User record
            $this->delete();

            DB::commit();
            self::$isCascading = $isAlreadyCascading;
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            self::$isCascading = $isAlreadyCascading;
            throw $e;
        }
    }

    public const PAYMENT_STATUS_PAID = 'Paid';
    public const PAYMENT_STATUS_UNPAID = 'Unpaid';

    public function getEntityImage(): string
    {
        if ($this->entity && method_exists($this->entity, 'getEntityImage')) {
            return $this->entity->getEntityImage();
        }

        if ($this->entity_type === \App\Models\Borrower\Borrower::class || $this->user_type === self::BORROWER) {
             return asset('avatar/avatar-borrower.jpg');
        }

        return asset('avatar/avatar-partner.jpg');
    }
}
