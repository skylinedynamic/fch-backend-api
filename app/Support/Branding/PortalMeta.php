<?php

namespace App\Support\Branding;

/**
 * What a portal's <head> says about it to a link preview: title, description,
 * image, favicon and canonical URL, for one tenant's branding.
 *
 * Link previews (WhatsApp, Facebook, LinkedIn, Slack) read these straight from
 * the HTML and never run the portal's JavaScript, so they are rendered on the
 * server and included into index.html by Nginx. Kept free of the database so
 * the choices can be read and tested on their own.
 */
final class PortalMeta
{
    public const PORTAL_BORROWER = 'borrower';
    public const PORTAL_PARTNER = 'partner';

    private const PORTALS = [
        self::PORTAL_BORROWER => [
            'label' => 'Borrower Portal',
            'description' => 'Sign in to the %s borrower portal to apply for funding and track your applications.',
        ],
        self::PORTAL_PARTNER => [
            'label' => 'Partner Portal',
            'description' => 'Sign in to the %s partner portal to manage your borrowers and their funding.',
        ],
    ];

    /**
     * Logos tried for the preview image, in order. The sidebar logo comes first -
     * it is the tenant's main mark, the one the settings form asks for first -
     * and the others stand in when it is missing or an SVG.
     */
    private const IMAGE_KEYS = ['logo_sidebar', 'logo_document', 'logo_mail', 'logo_mobile'];

    /** The placeholder service's SVG default, which previews cannot show. */
    private const PLACEHOLDER_PATTERN = '#^(https://placehold\.co/\d+x\d+)(?=\?|$)#';

    /**
     * @param  array<string, mixed>  $branding  AppConfigurationService::getBrandingSettings()
     * @param  string  $portal  one of the PORTAL_* constants; anything else is the borrower portal
     * @param  ?string  $domain  the portal hostname being previewed
     * @return array{title: string, site_name: string, description: string, image: ?string, favicon: ?string, url: ?string}
     */
    public static function build(array $branding, string $portal, ?string $domain): array
    {
        $portal = array_key_exists($portal, self::PORTALS) ? $portal : self::PORTAL_BORROWER;
        $siteName = self::text($branding['site_name'] ?? null) ?? (string) config('app.name');

        return [
            'title' => $siteName . ' | ' . self::PORTALS[$portal]['label'],
            'site_name' => $siteName,
            'description' => self::text($branding['site_slogan'] ?? null)
                ?? sprintf(self::PORTALS[$portal]['description'], $siteName),
            'image' => self::image($branding),
            // Null leaves the portal's own packaged icon, which index.html declares.
            'favicon' => self::text($branding['favicon_full_url'] ?? null),
            'url' => self::url($domain),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return ($value === '' || $value === 'null') ? null : $value;
    }

    /**
     * The first logo a preview can actually show. SVG is skipped - Facebook,
     * WhatsApp and LinkedIn all ignore it - and the name placeholder is asked
     * for as PNG for the same reason.
     *
     * @param  array<string, mixed>  $branding
     */
    private static function image(array $branding): ?string
    {
        foreach (self::IMAGE_KEYS as $key) {
            $url = self::text($branding[$key . '_full_url'] ?? null);

            if ($url === null) {
                continue;
            }

            if (preg_match(self::PLACEHOLDER_PATTERN, $url)) {
                return preg_replace(self::PLACEHOLDER_PATTERN, '$1/png', $url);
            }

            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($extension !== 'svg') {
                return $url;
            }
        }

        return null;
    }

    /**
     * The portal's own address, only for something that really is a hostname -
     * it comes from the request and is written back into the page.
     */
    private static function url(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));

        if ($domain === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $domain)) {
            return null;
        }

        return 'https://' . $domain . '/';
    }
}
