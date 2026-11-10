<?php

namespace App\Models\Calendly;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's Calendly account: the OAuth tokens to act as it (encrypted at
 * rest, never serialised) and its webhook subscription. There is at most one.
 */
class CalendlyConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    /** Calendly refused the refresh token - someone has to connect it again. */
    public const STATUS_RECONNECT = 'needs_reconnect';

    protected $table = 'calendly_connections';

    protected $fillable = [
        'connected_by',
        'calendly_user_uri',
        'calendly_organization_uri',
        'name',
        'email',
        'scheduling_url',
        'timezone',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'webhook_subscription_uri',
        'status',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** The connected account, if any. */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
