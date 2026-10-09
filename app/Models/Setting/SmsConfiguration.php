<?php

namespace App\Models\Setting;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's SMS account. `credentials` holds the provider's keys (for
 * Twilio: account_sid, auth_token, messaging_service_sid) and is encrypted at
 * rest; it is hidden so it never ends up in a response by accident.
 */
class SmsConfiguration extends Model
{
    protected $fillable = [
        'partner_id',
        'country',
        'provider',
        'is_enabled',
        'from_number',
        'credentials',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
