<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingApplicationDocument extends Model
{
    use SoftDeletes;

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

    protected $table = 'funding_application_documents';

    protected $fillable = [
        'document_number',
        'funding_application_id',
        'borrower_id',
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
     * Get the document URL.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        return asset('storage/' . $this->path);
    }

    /**
     * Get the funding application that owns the document.
     */
    public function fundingApplication(): BelongsTo
    {
        return $this->belongsTo(FundingApplication::class);
    }

    /**
     * Get the borrower that owns the document.
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    /**
     * Get the document type.
     */
    public function docType(): BelongsTo
    {
        return $this->belongsTo(BorrowerDocType::class, 'borrower_doc_type_id');
    }
}
