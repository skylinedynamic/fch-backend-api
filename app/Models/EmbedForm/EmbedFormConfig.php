<?php

namespace App\Models\EmbedForm;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmbedFormConfig extends Model
{
    protected $table = 'embedded_form_configs';

    protected $guarded = [];

    protected $casts = [
        'allowed_domains' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EmbedFormSubmission::class, 'form_config_id');
    }

    /**
     * Generate a unique public embed key for a partner.
     */
    public static function generatePublicKey(): string
    {
        do {
            $key = 'fch_pub_' . bin2hex(random_bytes(16));
        } while (self::where('public_key', $key)->exists());

        return $key;
    }
}
