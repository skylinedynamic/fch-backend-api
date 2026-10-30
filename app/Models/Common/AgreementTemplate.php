<?php

namespace App\Models\Common;

use App\Casts\AgreementImages;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    // The template's images are kept on disk, not as base64 in the columns.
    protected $casts = [
        'template_html' => AgreementImages::class,
        'template_json' => AgreementImages::class,
        'is_active' => 'boolean',
    ];

    /**
     * Get the partner that owns the agreement template.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgreementTemplateVersion::class);
    }

    /**
     * The version holding the template's current wording, kept now if the
     * wording changed since the last one (or the template predates versions).
     */
    public function currentVersion(): AgreementTemplateVersion
    {
        return DB::transaction(function () {
            // One writer at a time, so two saves cannot claim the same version number.
            static::whereKey($this->getKey())->lockForUpdate()->first();

            $latest = $this->versions()->orderByDesc('version')->first();

            // Compared as stored (image references, not the images themselves).
            $wording = [
                'template_html' => $this->getAttributes()['template_html'] ?? null,
                'template_json' => $this->getAttributes()['template_json'] ?? null,
            ];

            if ($latest && array_intersect_key($latest->getAttributes(), $wording) == $wording) {
                return $latest;
            }

            return $this->versions()->create($wording + [
                'version' => ($latest?->version ?? 0) + 1,
                'created_by' => auth()->id(),
            ]);
        });
    }
}
