<?php

namespace App\Models\Common;

use App\Casts\AgreementImages;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreement a partner saved as a named template (Agreement Builder › Save as
 * Template), to start a later agreement from. Holds the builder's blocks only.
 */
class AgreementSavedTemplate extends Model
{
    /** The longest template name. */
    public const NAME_MAX = 100;

    protected $fillable = [
        'partner_id',
        'slug',
        'name',
        'template_json',
        'created_by',
    ];

    // The template's images are kept on disk, not as base64 in the column.
    protected $casts = [
        'template_json' => AgreementImages::class,
    ];

    /** The agreement types a template can be saved from (AgreementTemplate::SLUG_*). */
    public static function slugs(): array
    {
        return [AgreementTemplate::SLUG_PARTNER, AgreementTemplate::SLUG_BORROWER];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
