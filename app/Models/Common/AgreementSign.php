<?php

namespace App\Models\Common;

use App\Casts\AgreementImages;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AgreementSign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'agreement_template_id',
        'agreement_template_version_id',
        'ip_address',
        'content_html',
        'content_hash',
        'user_agent',
        'field_values',
    ];

    // The signed HTML's images are kept on disk, not as base64 in the column.
    protected $casts = [
        'content_html' => AgreementImages::class,
        'field_values' => 'array',
    ];

    /**
     * Get the owning entity model (Partner, Borrower, etc.).
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agreementTemplate(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class);
    }
}
