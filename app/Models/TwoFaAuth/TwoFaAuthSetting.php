<?php

namespace App\Models\TwoFaAuth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFaAuthSetting extends Model
{
    use HasFactory;

    protected $table = 'user_two_factor_settings';

    protected $fillable = [
        'user_id',
        'enabled',
        'method', 
        'secret', 
        'confirmed_at',
        'recovery_codes', 
        'code', 
        'code_expires_at',
        'attempts',
        'locked_until',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'confirmed_at' => 'datetime',
        'code_expires_at' => 'datetime',
        'locked_until' => 'datetime',
        'recovery_codes' => 'array',
    ];

    public const TOTP = 'totp';
    public const SMS = 'sms';
    public const EMAIL = 'email';

    public const METHODS = [
        self::TOTP => 'TOTP (Google Authenticator)',
        self::SMS => 'SMS',
        self::EMAIL => 'Email',
    ];

   
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User\User::class);
    }

 
    public function isEnabled(): bool
    {
        return $this->enabled && !is_null($this->confirmed_at) && !is_null($this->method);
    }

    /**
     * Check if 2FA is active (enabled and not locked).
     */
    public function isActive(): bool
    {
        return $this->isEnabled() && !$this->isLocked();
    }

    /**
     * Check if user is currently locked out from 2FA attempts.
     */
    public function isLocked(): bool
    {
        return $this->locked_until && now()->lessThanOrEqualTo($this->locked_until);
    }


    public function hasMethod(string $method): bool
    {
        return $this->isEnabled() && $this->method === $method;
    }


    public function hasTotpEnabled(): bool
    {
        return $this->hasMethod(self::TOTP);
    }

    
    public function hasSmsEnabled(): bool
    {
        return $this->hasMethod(self::SMS);
    }

  
    public function hasEmailEnabled(): bool
    {
        return $this->hasMethod(self::EMAIL);
    }


    public function getEnabledMethod(): ?string
    {
        return $this->isEnabled() ? $this->method : null;
    }

  
    public function needsSetup(): bool
    {
        return !$this->isEnabled();
    }

    /**
     * Check if user can attempt 2FA verification.
     */
    public function canAttemptVerification(): bool
    {
        return $this->isEnabled() && !$this->isLocked();
    }

  
    public function getLockoutTimeRemaining(): int
    {
        if (!$this->isLocked()) {
            return 0;
        }
        
        return max(0, now()->diffInSeconds($this->locked_until, false));
    }

  
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true)
                    ->whereNotNull('confirmed_at')
                    ->whereNotNull('method');
    }

    /**
     * Scope to get only active (enabled and not locked) 2FA settings.
     */
    public function scopeActive($query)
    {
        return $query->enabled()
                    ->where(function ($q) {
                        $q->whereNull('locked_until')
                          ->orWhere('locked_until', '<=', now());
                    });
    }
}