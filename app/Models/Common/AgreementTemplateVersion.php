<?php

namespace App\Models\Common;

use App\Casts\AgreementImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One wording of an agreement template, kept as it was so a signature can
 * point at exactly what was signed. Created by AgreementTemplate::currentVersion();
 * never changed or deleted afterwards (it goes only with its template).
 */
class AgreementTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agreement_template_id',
        'version',
        'template_html',
        'template_json',
        'created_by',
    ];

    // The version's images are kept on disk, not as base64 in the columns.
    protected $casts = [
        'version' => 'integer',
        'template_html' => AgreementImages::class,
        'template_json' => AgreementImages::class,
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An agreement template version cannot be changed.'));
        static::deleting(fn () => throw new LogicException('An agreement template version cannot be deleted.'));
    }

    public function agreementTemplate(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class);
    }
}
