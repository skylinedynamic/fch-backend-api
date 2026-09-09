<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppConfiguration extends Model
{
    use HasFactory;

    public const MODULE_AGREEMENT = 'agreement';
    public const MODULE_BRANDING = 'branding';
    public const MODULE_MICROBILT = 'microbilt';
    public const MODULE_WEBSITE = 'website';
    public const MODULE_CREDIT_PROFILE = 'credit_profile';
    public const MODULE_OPENAI = 'openai';

    protected $fillable = [
        'partner_id',
        'module',
        'key',
        'value'
    ];

    public const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'primary_logo',
        'secondary_logo',
        'favicon',
        'website_favicon',
        ];

    /**
     * Set a configuration value
     */
    public static function set(string $key, $value, ?string $module = null, ?int $partnerId = null)
    {
        return self::updateOrCreate(
            [
                'key' => $key,
                'module' => $module,
                'partner_id' => $partnerId,
            ],
            [
                'value' => (string) $value
            ]
        );
    }

    /**
     * Get a configuration value
     */
    public static function get(string $key, $default = null, ?string $module = null, ?int $partnerId = null)
    {
        $config = self::where('key', $key)
            ->when($module, fn($q) => $q->where('module', $module))
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->first();

        return $config ? $config->value : $default;
    }
}
