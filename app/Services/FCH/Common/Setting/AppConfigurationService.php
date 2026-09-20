<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Cache;

class AppConfigurationService
{
    /**
     * Cache generation counter for branding. Bumping it invalidates every
     * tenant at once - see getBrandingSettings().
     */
    private const BRANDING_VERSION_KEY = 'branding:version';

    /**
     * Get a configuration value by key and module.
     */
    public function get(string $key, $default = null, ?string $module = null, ?int $partnerId = null)
    {
        return AppConfiguration::get($key, $default, $module, $partnerId);
    }

    /**
     * Set a configuration value for a key and module.
     */
    public function set(string $key, $value, ?string $module = null, ?int $partnerId = null)
    {
        return AppConfiguration::set($key, $value, $module, $partnerId);
    }

    /**
     * Get configurations belonging to a specific module.
     */
    public function getByModule(string $module, ?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->where('module', $module)
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get multiple configurations by keys.
     */
    public function getMultiple(array $keys, ?string $module = null, ?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->whereIn('key', $keys)
            ->when($module, fn($q) => $q->where('module', $module))
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all configurations.
     */
    public function getAll(?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get branding settings with custom fallbacks and white-label checks.
     */
    public function getBrandingSettings($partnerId = null, bool $inheritLogosFromHost = true): array
    {
        // Branding is read on nearly every request, and once per row on invoice
        // lists, so memoise it per request and cache it across requests.
        //
        // The key carries a generation counter rather than being invalidated per
        // partner: the host's values are the fallback source for every other
        // tenant, so a host edit has to invalidate everyone. Bumping one counter
        // does that without enumerating partners.
        static $memo = [];

        $cacheKey = sprintf(
            '%s:v%d:%s:%d',
            self::BRANDING_VERSION_KEY,
            self::brandingCacheVersion(),
            $partnerId ?? 'none',
            $inheritLogosFromHost ? 1 : 0
        );

        if (isset($memo[$cacheKey])) {
            return $memo[$cacheKey];
        }

        return $memo[$cacheKey] = Cache::remember(
            $cacheKey,
            now()->addHours(6),
            fn () => $this->buildBrandingSettings($partnerId, $inheritLogosFromHost)
        );
    }

    /**
     * Current branding cache generation.
     */
    private static function brandingCacheVersion(): int
    {
        return (int) (Cache::get(self::BRANDING_VERSION_KEY) ?: 1);
    }

    /**
     * Invalidate every tenant's cached branding.
     *
     * Called whenever a branding value is written. Cheap: one counter write, no
     * key enumeration, and stale entries simply age out under the old version.
     */
    public static function bustBrandingCache(): void
    {
        Cache::forever(self::BRANDING_VERSION_KEY, self::brandingCacheVersion() + 1);
    }

    /**
     * Build a tenant's branding from the database. Uncached - go through
     * getBrandingSettings() instead.
     */
    private function buildBrandingSettings($partnerId = null, bool $inheritLogosFromHost = true): array
    {
        $hostSettings = AppConfiguration::where('partner_id', Partner::host()?->id)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->pluck('value', 'key');

        $settings = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->pluck('value', 'key');

        $partner = $partnerId ? Partner::find($partnerId) : null;

        $data = [];

        // Branding resolves its OWN keys only. IMAGE_KEYS belongs to the website
        // module and was merged in here while the branding keys were mid-rename;
        // including it now would resurrect the legacy names this module has
        // dropped, and pull website CMS values into tenant branding.
        $imageKeys = AppConfiguration::BRANDING_IMAGE_KEYS;

        foreach ($settings as $key => $val) {
            $data[$key] = $val === '' ? null : $val;
        }

        /**
         * Walk each surface logo's fallback chain within ONE tenant's own values.
         *
         * Two passes, so a chain pointing at another surface key (mail borrowing
         * the sidebar logo) still sees that key's own fallback filled in first.
         */
        $resolveChain = static function (array $values) use ($imageKeys): array {
            $out = [];
            foreach ($imageKeys as $key) {
                $val = $values[$key] ?? null;
                $out[$key] = ($val === '' || $val === 'null') ? null : $val;
            }

            for ($pass = 0; $pass < 2; $pass++) {
                foreach (AppConfiguration::BRANDING_LOGO_FALLBACKS as $newKey => $chain) {
                    if (($out[$newKey] ?? null) !== null) {
                        continue;
                    }

                    foreach ((array) $chain as $fallbackKey) {
                        if (($out[$fallbackKey] ?? null) !== null) {
                            $out[$newKey] = $out[$fallbackKey];
                            break;
                        }
                    }
                }
            }

            return $out;
        };

        // A tenant's OWN logos are exhausted before the host's are considered.
        //
        // These used to run the other way round: the host fallback filled every
        // missing key first, so a partner who had only uploaded under the older
        // names (primary_logo / secondary_logo) had the host's logo_document
        // filled in, and the chain then skipped that key as already resolved.
        // Every partner's invoice showed the host's logo even though they had
        // one of their own.
        $ownLogos = $resolveChain($data);
        $hostLogos = $resolveChain($hostSettings->toArray());

        foreach ($imageKeys as $imageKey) {
            $resolved = $ownLogos[$imageKey] ?? ($inheritLogosFromHost ? ($hostLogos[$imageKey] ?? null) : null);

            $data[$imageKey] = $resolved;
            $data[$imageKey . '_full_url'] = $resolved
                ? encodeUrl(asset('storage/' . ltrim($resolved, '/')))
                : null;
        }

        if (($data['site_name'] ?? null) === null) {
            $hostSiteName = $hostSettings['site_name'] ?? null;
            $data['site_name'] = $hostSiteName === '' ? null : $hostSiteName;
        }

        if (empty($data['primary_color']) || $data['primary_color'] === 'null') {
            $hostPrimary = $hostSettings['primary_color'] ?? null;
            $data['primary_color'] = $hostPrimary === '' ? null : $hostPrimary;
        }

        if (empty($data['secondary_color']) || $data['secondary_color'] === 'null') {
            $hostSecondary = $hostSettings['secondary_color'] ?? null;
            $data['secondary_color'] = $hostSecondary === '' ? null : $hostSecondary;
        }

        // Plain identity text falls back to the host, the same way site_name does.
        $inheritedKeys = [
            'site_slogan',
        ];

        foreach ($inheritedKeys as $inheritedKey) {
            if (empty($data[$inheritedKey]) || $data[$inheritedKey] === 'null') {
                $hostValue = $hostSettings[$inheritedKey] ?? null;
                $data[$inheritedKey] = $hostValue === '' ? null : $hostValue;
            }
        }

        // The sidebar and top bar colours are deliberately NOT inherited.
        //
        // They are refinements of whichever primary/secondary a tenant ends up
        // with, not values that travel between tenants. Borrowing them from the
        // host applied the host's sidebar colour on top of the partner's own
        // brand colour: the host had set a pale sidebar, so every partner's menu
        // came out pale no matter what their primary was, and changing the brand
        // colour appeared to do nothing to the menu.
        //
        // Left null, each portal derives them from that tenant's own colours. A
        // partner who has set no colours at all still matches the host, because
        // primary_color and secondary_color are inherited above and everything
        // here follows from those.
        $derivedKeys = [
            'topbar_bg_color',
            'topbar_text_color',
            'sidebar_bg_color',
            'sidebar_text_color',
            'sidebar_icon_color',
            'sidebar_hover_bg_color',
            'sidebar_text_hover_color',
            'sidebar_icon_hover_color',
            'sidebar_active_bg_color',
            'sidebar_active_text_color',
            'sidebar_active_icon_color',
        ];

        foreach ($derivedKeys as $derivedKey) {
            if (empty($data[$derivedKey]) || $data[$derivedKey] === 'null') {
                $data[$derivedKey] = null;
            }
        }

        // Whether this partner is white-labelled is a property of the partner, not an
        // editable setting. The borrower portal reads it to decide whose name to show.
        $data['is_white_label'] = $partner
            ? ($partner->is_host || $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)
            : false;

        return $data;
    }
}
