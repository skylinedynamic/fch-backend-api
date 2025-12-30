<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class BorrowerDocument extends Model
{
    use SoftDeletes;

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
