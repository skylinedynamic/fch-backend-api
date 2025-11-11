<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'user_id',
        'borrower_doc_type_id',
        'name',
        'path',
        'disk',
        'original_name',
        'extension',
        'mime',
        'size',
    ];

    protected $appends = ['url'];

    /**
     * Accessor for computed file URL.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        try {
            return Storage::disk($this->disk ?: config('filesystems.default'))
                ->url($this->path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Scope: constrain to owner/user.
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: filter by doc type id.
     */
    public function scopeType(Builder $query, ?int $typeId): Builder
    {
        return $typeId ? $query->where('borrower_doc_type_id', $typeId) : $query;
    }

    /**
     * Scope: filter by file name (partial match).
     */
    public function scopeFileName(Builder $query, ?string $fileName): Builder
    {
        return $fileName ? $query->where('name', 'like', "%" . $fileName . "%") : $query;
    }

    /**
     * Scope: full-text-ish search across common columns.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = $term ? trim($term) : null;
        if (!$term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%$term%")
              ->orWhere('original_name', 'like', "%$term%")
              ->orWhere('mime', 'like', "%$term%")
              ->orWhere('extension', 'like', "%$term%");
        });
    }
}