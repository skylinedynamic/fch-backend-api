<?php

namespace App\Models\Calendly;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One partner's Calendly - the host's, a WLP's or a certified partner's -
 * with that partner's own Calendly OAuth app keys (Client ID, Client Secret,
 * Webhook Signing Key), set in Settings > Calendly by the partner account
 * itself or by the host for it. The partner's team books into it and syncs
 * it; its borrowers book with it. The secrets and OAuth tokens are encrypted
 * at rest and kept out of serialisation; Settings > Calendly returns the app
 * keys on purpose, only to the partner's own account and the host account.
 */
class CalendlyConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    /** Created for the Calendly sign-in, which has not been completed (or was disconnected). */
    public const STATUS_PENDING = 'pending';

    /** Calendly refused the refresh token - someone has to connect it again. */
    public const STATUS_RECONNECT = 'needs_reconnect';

    protected $table = 'calendly_connections';

    protected $fillable = [
        'partner_id',
        'client_id',
        'client_secret',
        'webhook_signing_key',
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
        'client_secret',
        'webhook_signing_key',
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'webhook_signing_key' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** The partner's Calendly app keys are saved (Client ID and Client Secret). */
    public function hasKeys(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    /** A borrower's login - they book with their partner's Calendly, they do not manage one. */
    public static function isBorrower(User $user): bool
    {
        return $user->entity_type === Borrower::class || $user->user_type === User::BORROWER;
    }

    /** The Calendly a login uses: their partner's (a borrower's partner's, for a borrower). */
    public static function forUser(User $user): ?self
    {
        return $user->partner_id ? static::where('partner_id', $user->partner_id)->first() : null;
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** Signed in to Calendly, with FCH's app set up to act and refresh with. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->hasKeys() && filled($this->access_token);
    }

    /** The host's own Calendly: its meetings may be with anyone, as the host's are. */
    public function isHosts(): bool
    {
        // Asked, not lazy-loaded: the hourly sync holds many connections at once.
        return (bool) Partner::whereKey($this->partner_id)->value('is_host');
    }

    /**
     * The partner's own account - the login whose record is the host, the WLP
     * or the certified partner itself. Only it configures (connects and
     * disconnects) the partner's Calendly; its staff and users do not.
     */
    public static function isPartnerAccount(User $user): bool
    {
        return $user->entity_type === Partner::class
            && $user->partner_id
            && (int) $user->entity_id === (int) $user->partner_id;
    }

    /** The host's own account - may also configure any partner's Calendly (Partners > partner > Configuration). */
    public static function isHostAccount(User $user): bool
    {
        return self::isPartnerAccount($user) && (bool) Partner::whereKey($user->partner_id)->value('is_host');
    }

    /** This partner's own account, or the host account - may configure it. */
    public function isManagedBy(User $user): bool
    {
        return self::isPartnerAccount($user)
            && ((int) $user->partner_id === (int) $this->partner_id || self::isHostAccount($user));
    }
}
