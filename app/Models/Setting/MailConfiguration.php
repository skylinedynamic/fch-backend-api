<?php

namespace App\Models\Setting;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mail provider's settings for a partner - or, with no partner, the one
 * platform-wide fallback that the host partner keeps.
 *
 * `credentials` holds the provider's fields (see config/mail_configuration.php)
 * and is encrypted at rest; it is hidden so it never ends up in a response by
 * accident.
 *
 * Verified means a code sent through exactly these settings came back. The
 * fingerprint is taken of everything that decides where and how mail is sent,
 * so changing any of it - a password, the host, the sender address - leaves
 * the configuration unverified without anyone having to remember to reset it.
 */
class MailConfiguration extends Model
{
    public const PROVIDER_SMTP = 'smtp';
    public const PROVIDER_SES = 'aws_ses';

    protected $fillable = [
        'partner_id',
        'provider',
        'from_address',
        'bcc_emails',
        'credentials',
        'is_default',
    ];

    protected $hidden = [
        'credentials',
        'verified_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
            'verified_by' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function providers(): array
    {
        return config('mail_configuration.providers', []);
    }

    /**
     * This configuration's provider's fields, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return self::providers()[$this->provider]['fields'] ?? [];
    }

    public function credential(string $key): mixed
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return $value === '' ? null : $value;
    }

    public function scopeFallback(Builder $query): Builder
    {
        return $query->whereNull('partner_id');
    }

    public function scopeForPartner(Builder $query, int $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    public function isFallback(): bool
    {
        return $this->partner_id === null;
    }

    /**
     * A hash of everything that decides where and how mail is sent. The BCC
     * list is left out: who gets a copy does not change whether the settings
     * can send.
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function fingerprintFor(string $provider, ?string $fromAddress, array $credentials): string
    {
        $fields = array_keys(self::providers()[$provider]['fields'] ?? []);
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = (string) ($credentials[$field] ?? '');
        }

        return hash('sha256', json_encode([
            'provider' => $provider,
            'from_address' => strtolower(trim((string) $fromAddress)),
            'credentials' => $values,
        ]));
    }

    public function fingerprint(): string
    {
        return self::fingerprintFor($this->provider, $this->from_address, $this->credentials ?? []);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null
            && is_string($this->verified_fingerprint)
            && hash_equals($this->verified_fingerprint, $this->fingerprint());
    }

    /**
     * Whether these settings carry everything their provider needs to send.
     */
    public function isUsable(): bool
    {
        if (!filter_var($this->from_address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $fields = $this->fields();
        if ($fields === []) {
            return false;
        }

        foreach ($fields as $key => $field) {
            if (!empty($field['required']) && ($this->credential($key) === null || $this->credential($key) === '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The flat settings App\Mail\Traits\MailConfig hands to the mailer.
     */
    public function toMailSettings(): \stdClass
    {
        $settings = new \stdClass();
        $settings->email_provider = $this->provider;
        $settings->from_address = $this->from_address;
        $settings->bcc_emails = $this->bcc_emails;

        foreach (array_keys($this->fields()) as $key) {
            $settings->{$key} = $this->credential($key);
        }

        return $settings;
    }
}
