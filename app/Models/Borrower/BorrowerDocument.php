<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

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
        return $value ? asset("storage/borrowers/{$this->borrower_id}/{$value}") : null;
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
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
}
