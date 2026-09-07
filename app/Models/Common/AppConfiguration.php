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
     * Logo keys owned by the branding module, named for the surface they appear on.
     *
     * The legacy names carried no such meaning - `primary_logo` was "the white text
     * one" by convention only, which is how it ended up rendered on white invoices.
     * Naming by surface removes the guesswork: the key says where the file is used,
     * so a consumer cannot pick the wrong one. The older names stay listed so a
     * partner who has not re-uploaded still resolves.
     */
    public const BRANDING_IMAGE_KEYS = [
        'logo_sidebar',
        'logo_document',
        'logo_mail',
        'logo_mobile',
        'logo_mark',
        // Superseded, read-only fallbacks.
        'logo_on_light',
        'logo_on_dark',
        'primary_logo',
        'secondary_logo',
        'favicon',
    ];

    /**
     * Fallback chain for each surface logo, tried in order until one resolves.
     *
     * Sidebar and mail both sit on a dark band, so they share the same ancestry;
     * documents are printed on white and inherit the light-background lineage.
     */
    public const BRANDING_LOGO_FALLBACKS = [
        'logo_sidebar'  => ['logo_on_dark', 'primary_logo', 'logo_mail', 'logo_document'],
        'logo_mail'     => ['logo_on_dark', 'primary_logo', 'logo_sidebar', 'logo_document'],
        'logo_document' => ['logo_on_light', 'secondary_logo', 'logo_sidebar', 'logo_mail'],
        // The mobile bar is the top bar, which is light by default, so the mobile
        // logo inherits the document lineage before the dark-background one.
        'logo_mobile'   => ['logo_on_light', 'secondary_logo', 'logo_document', 'logo_mark', 'logo_sidebar'],
        'logo_mark'     => ['favicon'],
        // Kept populated for consumers not yet migrated off the older names.
        'logo_on_dark'  => ['logo_sidebar', 'primary_logo'],
        'logo_on_light' => ['logo_document', 'secondary_logo'],
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
