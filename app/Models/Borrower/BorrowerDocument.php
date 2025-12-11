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

    protected $fillable = [
        'document_type_id',
        'borrower_id',
        'application_id',
        'file_name',
        'file_path',
        'created_by',
        'deleted_by',
    ];

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
        if (!$this->file_path) {
            return null;
        }

        try {
            return encodeUrl(Storage::disk('public')->url($this->file_path));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Scope: constrain to owner/borrower.
     */
    public function scopeOwnedBy(Builder $query, int $borrowerId): Builder
    {
        return $query->where('borrower_id', $borrowerId);
    }
}
