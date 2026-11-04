<?php

namespace App\Models\Audit;

use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use App\Models\Borrower\Borrower;

/**
 * One event in a funding application's audit trail. Append-only: written
 * only by App\Services\Audit\FundingApplicationAuditTrail, never updated or
 * deleted, and chained to the event before it on the same application
 * (prev_hash -> hash), so a row changed behind the trail's back is detected.
 *
 * @property array|null $changes list of {field, old, new}
 * @property array|null $context
 */
class FundingApplicationAuditEvent extends Model
{
    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_SIGNED = 'signed';
    public const EVENT_SIGNATURE_CLEARED = 'signature_cleared';
    public const EVENT_SIGNED_COPY_KEPT = 'signed_copy_kept';
    public const EVENT_DOCUMENT_ADDED = 'document_added';
    public const EVENT_DOCUMENT_REMOVED = 'document_removed';
    public const EVENT_CO_BORROWER_ADDED = 'co_borrower_added';
    public const EVENT_CO_BORROWER_UPDATED = 'co_borrower_updated';
    public const EVENT_CO_BORROWER_REMOVED = 'co_borrower_removed';
    public const EVENT_DOWNLOADED = 'downloaded';
    public const EVENT_DELETED = 'deleted';
    public const EVENT_RESTORED = 'restored';

    /** Recorded by the document's hash only: the trail keeps no copy of a document. */
    public const DOCUMENT_EVENTS = [self::EVENT_DOCUMENT_ADDED, self::EVENT_DOCUMENT_REMOVED];

    public const EVENTS = [
        self::EVENT_CREATED,
        self::EVENT_UPDATED,
        self::EVENT_STATUS_CHANGED,
        self::EVENT_SIGNED,
        self::EVENT_SIGNATURE_CLEARED,
        self::EVENT_SIGNED_COPY_KEPT,
        self::EVENT_DOCUMENT_ADDED,
        self::EVENT_DOCUMENT_REMOVED,
        self::EVENT_CO_BORROWER_ADDED,
        self::EVENT_CO_BORROWER_UPDATED,
        self::EVENT_CO_BORROWER_REMOVED,
        self::EVENT_DOWNLOADED,
        self::EVENT_DELETED,
        self::EVENT_RESTORED,
    ];

    /** The application PDF, the signature certificate, or both in a ZIP. */
    public const FILE_APPLICATION_PDF = 'application_pdf';
    public const FILE_SIGNATURE_CERTIFICATE = 'signature_certificate';
    public const FILE_APPLICATION_ZIP = 'application_zip';

    public $timestamps = false;

    protected $table = 'funding_application_audit_events';

    protected $guarded = ['id'];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'is_legacy' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(__('audit.append_only')));
        static::deleting(fn () => throw new LogicException(__('audit.append_only')));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function fundingApplication(): BelongsTo
    {
        return $this->belongsTo(FundingApplication::class)->withTrashed();
    }

    /**
     * Who reads the trail, as FundingApplication::reviewableBy() decides who
     * reviews an application: the host every application, a partner its own,
     * a client none. By the partner the event was recorded under, so the
     * trail stays readable after the application's row is gone.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user || $user->entity_type === Borrower::class || $user->user_type === User::BORROWER) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->user_type === User::ADMIN) {
            return $query;
        }

        return $user->partner_id
            ? $query->where($this->qualifyColumn('partner_id'), $user->partner_id)
            : $query->whereRaw('1 = 0');
    }
}
