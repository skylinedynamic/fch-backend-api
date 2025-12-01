<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartnerDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'partner_documents';

    protected $fillable = [
        'partner_id',
        'document_name',
        'file_path',
        'size',
    ];

    /**
     * Get the partner that owns the document.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
