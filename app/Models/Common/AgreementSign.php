<?php

namespace App\Models\Common;

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
        'ip_address',
        'content_html',
        'field_values',
    ];

    protected $casts = [
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
