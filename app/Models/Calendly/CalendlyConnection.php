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
        'borrower_id',
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

    /** A borrower's login - books with their own Calendly once connected, else their partner's. */
    public static function isBorrower(User $user): bool
    {
        return $user->entity_type === Borrower::class || $user->user_type === User::BORROWER;
    }

    /** The partner's own Calendly (the host's, a WLP's, a certified partner's) - not a borrower's. */
    public static function forPartner(?int $partnerId): ?self
    {
        return $partnerId ? static::where('partner_id', $partnerId)->whereNull('borrower_id')->first() : null;
    }

    /** A borrower login's own Calendly, connected or not; null for anyone else. */
    public static function ownedByBorrower(User $user): ?self
    {
        return self::isBorrower($user) && $user->entity_id
            ? static::where('borrower_id', $user->entity_id)->first()
            : null;
    }

    /**
     * The Calendly a login books with and reads: their partner's for its team;
     * for a borrower, their own once signed in, else their partner's.
     */
    public static function forUser(User $user): ?self
    {
        $own = self::ownedByBorrower($user);

        return $own?->isActive() ? $own : self::forPartner($user->partner_id);
    }

    /** A borrower's own Calendly (not their partner's). */
    public function isBorrowers(): bool
    {
        return $this->borrower_id !== null;
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
        // A borrower's own is never the host's, whoever their partner is.
        if ($this->isBorrowers()) {
            return false;
        }

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

    /**
     * May connect and disconnect it: a borrower's own, that borrower; a
     * partner's, its own account or the host account.
     */
    public function isManagedBy(User $user): bool
    {
        if ($this->isBorrowers()) {
            return self::isBorrower($user) && (int) $user->entity_id === (int) $this->borrower_id;
        }

        return self::isPartnerAccount($user)
            && ((int) $user->partner_id === (int) $this->partner_id || self::isHostAccount($user));
    }
}
