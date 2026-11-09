<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;
use App\Support\Files\FileUrl;
use App\Support\Files\Folder;

class BorrowerDocument extends Model
{
    use SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('borrower_document');
    }

    public function tapActivity(Activity $activity, string $eventName)
    {
        $activity->partner_id = $this->borrower?->partner_id;
        $activity->borrower_id = $this->borrower_id;

        $docTypeName = $this->documentType?->name ?? 'Document';

        $activity->description = match ($eventName) {
            'created' => "New document {$docTypeName} was submitted.",
            'updated' => "Document {$docTypeName}' status was updated to {$this->status}.",
            'deleted' => "Document {$docTypeName} was removed.",
            default => $activity->description,
        };

        if ($eventName === 'created') {
            $activity->properties = collect($activity->properties)->merge([
                'attachments' => [
                    'count' => 1,
                    'files' => [
                        [
                            'name' => $this->file_name,
                            'path' => $this->url,
                            'id' => $this->id,
                        ]
                    ]
                ]
            ]);
        }

        $activity->log_type = \App\Models\ActivityLog\ActivityLog::LOG_TYPE_DOCUMENT_UPLOAD;
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::created(function ($document) {
            $document->document_number = 'DOC-' . str_pad($document->id, 5, '0', STR_PAD_LEFT) . $document->id;
            $document->saveQuietly();
        });
    }

    protected $table = 'borrower_documents';

    public const STATUS_UPLOADED = 'Uploaded';
    public const STATUS_UNDER_REVIEW = 'Under Review';
    public const STATUS_ACCEPTED = 'Accepted';
    public const STATUS_REJECTED = 'Rejected';

    public const STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'document_number',
        'document_type_id',
        'borrower_id',
        'application_id',
        'file_name',
        'file_path',
        'status',
        'rejection_reason',
        'file_size',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * Get the file path with storage prefix.
     */
    public function getFilePathAttribute($value)
    {
        // BUG-016: a signed URL for a private file (the uploads disk decides).
        return $value ? FileUrl::for(self::storagePathFor($this->borrower_id, $value)) : null;
    }

    /** The iSoftPull reports' own folder (BUG-016), apart from the client's documents. */
    public const CREDIT_REPORT_FOLDER = Folder::CREDIT_REPORTS;

    /**
     * Where a document's file is on the uploads disk: a credit report keeps
     * its own path (credit-reports/{borrower}/...); any other document is in
     * the borrower's folder, under the name the row holds.
     */
    public static function storagePathFor(int|string|null $borrowerId, string $filePath): string
    {
        return str_starts_with($filePath, self::CREDIT_REPORT_FOLDER . '/') ? $filePath : Folder::BORROWERS . "/{$borrowerId}/{$filePath}";
    }

    /** This document's path on the uploads disk, or null when it has no file. */
    public function storagePath(): ?string
    {
        $raw = $this->getRawOriginal('file_path');

        return $raw ? self::storagePathFor($this->borrower_id, $raw) : null;
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    /** A document is reachable exactly as far as its borrower is - see Borrower::scopeVisibleTo(). */
    public function scopeVisibleTo($query, ?\App\Models\User\User $user)
    {
        return $query->whereHas('borrower', fn ($borrowers) => $borrowers->visibleTo($user));
    }

    public function documentType()
    {
        return $this->belongsTo(BorrowerDocType::class, 'document_type_id');
    }

    protected $appends = ['url'];

    /**
     * Accessor for computed file URL.
     */
    public function getUrlAttribute(): ?string
    {
        // Use the raw attribute to check if path exists
        $rawPath = $this->getRawOriginal('file_path');
        if (!$rawPath) {
            return null;
        }

        return $this->file_path;
    }

    /**
     * Scope: constrain to owner/borrower.
     */
    public function scopeOwnedBy(Builder $query, int $borrowerId): Builder
    {
        return $query->where('borrower_id', $borrowerId);
    }

    /**
     * The client's own documents: not the iSoftPull report kept for its credit
     * profile, which only Client > Credit Profile shows.
     */
    public function scopeWithoutCreditReports(Builder $query): Builder
    {
        return $query->where('is_credit_report', false);
    }
}
