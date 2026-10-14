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

    /**
     * Drop the cached branding whenever a branding row is written.
     *
     * Hooked on the model rather than only on AppConfigurationService::set() so
     * that direct updateOrCreate()/delete() calls - which several settings
     * screens use - cannot leave a stale brand cached behind them.
     */
    protected static function booted(): void
    {
        $bust = static function (self $config): void {
            if ($config->module === self::MODULE_BRANDING) {
                \App\Services\FCH\Common\Setting\AppConfigurationService::bustBrandingCache();
            }
        };

        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * Image keys owned by the `website` module - the public marketing site's CMS.
     * Unrelated to partner branding despite the overlapping names; see
     * BRANDING_IMAGE_KEYS for the per-tenant logos.
     */
    public const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'primary_logo',
        'secondary_logo',
        'favicon',
        'website_favicon',
    ];

    /**
     * Logo keys owned by the branding module - one per surface, and nothing else.
     *
     * These used to number ten: five real uploads plus five derived mirrors
     * (logo_on_light / logo_on_dark / primary_logo / secondary_logo, and favicon
     * duplicated as logo_mark) that the settings form wrote automatically for
     * consumers not yet moved off the older names. Half the keys were copies of
     * the other half, which is why nobody could tell which one a surface used.
     *
     * Naming by surface removes the guesswork: the key says where the file is
     * used, so a consumer cannot pick the wrong one.
     *
     * NOTE: the `website` module has its own `primary_logo` / `secondary_logo` /
     * `favicon` keys for the public marketing site. Same names, different module,
     * unrelated to these - see SiteSettingsService.
     */
    public const BRANDING_IMAGE_KEYS = [
        'logo_sidebar',
        'logo_document',
        'logo_mail',
        'logo_mobile',
        'favicon',
    ];

    /**
     * Keys whose presence means a tenant has actually configured their branding.
     *
     * Used by the "please finish your setup" guard. It asks whether a name and a
     * logo exist at all, so it names the sidebar logo - the one the settings form
     * requires first - rather than the retired primary_logo.
     */
    public const BRANDING_FRESHNESS_KEYS = [
        'site_name',
        'logo_sidebar',
    ];

    /**
     * Fallback chain for each surface logo, tried in order until one resolves.
     *
     * The sidebar is the only dark surface, so it is the last resort for the
     * light ones rather than their first choice. Mail, documents and mobile all
     * render on white and share each other first - a white logo on a white
     * header is the failure this ordering prevents.
     */
    public const BRANDING_LOGO_FALLBACKS = [
        'logo_sidebar'  => ['logo_document', 'logo_mail'],
        'logo_document' => ['logo_mail', 'logo_mobile', 'logo_sidebar'],
        'logo_mail'     => ['logo_document', 'logo_mobile', 'logo_sidebar'],
        'logo_mobile'   => ['logo_document', 'logo_mail', 'logo_sidebar'],
        'favicon'       => [],
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
    /**
     * A platform-wide setting (the OpenAI key, models and agents): the host's
     * row, or one saved with no partner at all - never another partner's.
     * A plain get() takes whichever row comes first, which made a partner's
     * stray row the value the whole platform ran on.
     */
    public static function platform(string $key, ?string $module = null, $default = null)
    {
        $hostId = \App\Models\Partner\Partner::where('is_host', 1)->value('id');

        $config = self::where('key', $key)
            ->when($module, fn($q) => $q->where('module', $module))
            ->where(fn($q) => $q->whereNull('partner_id')->when($hostId, fn($q) => $q->orWhere('partner_id', $hostId)))
            // The host's own row wins over an unowned one.
            ->orderByRaw('partner_id IS NULL')
            ->first();

        return $config ? $config->value : $default;
    }

    public static function get(string $key, $default = null, ?string $module = null, ?int $partnerId = null)
    {
        $config = self::where('key', $key)
            ->when($module, fn($q) => $q->where('module', $module))
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->first();

        return $config ? $config->value : $default;
    }
}
