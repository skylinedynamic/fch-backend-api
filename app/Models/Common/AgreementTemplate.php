<?php

namespace App\Models\Common;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'slug',
        'template_html',
        'template_json',
        'is_active',
    ];

    /**
     * Get the partner that owns the agreement template.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
