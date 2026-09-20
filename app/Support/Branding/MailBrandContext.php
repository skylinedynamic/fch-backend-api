<?php

namespace App\Support\Branding;

/**
 * Carries the resolved brand palette from a mailable to the mail layout.
 *
 * Each of the 40-odd mailables copies individual keys ('siteName', 'logoUrl')
 * into its own view data, so adding colours to the trait's return value alone
 * would never reach the Blade layout — which is why the branded header band did
 * not appear. Rather than edit every mailable, MailableBranding records the
 * palette here and a view composer on the layout picks it up.
 *
 * The value is per-process and short lived: it is overwritten on every call to
 * getBrandingData(), which each mailable makes immediately before rendering.
 * A queue worker rendering one mail after another therefore always sees the
 * palette belonging to the mail currently being built.
 */
class MailBrandContext
{
    /** @var array<string, mixed> */
    protected static array $palette = [];

    /** @param array<string, mixed> $palette */
    public static function set(array $palette): void
    {
        static::$palette = $palette;
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        return static::$palette;
    }

    public static function clear(): void
    {
        static::$palette = [];
    }

    /**
     * The brand variables the layout reads, with product defaults when no
     * mailable has recorded anything (a preview route, or a mail that does not
     * use the trait). Never returns nulls, so the layout has no undefined vars.
     *
     * @return array<string, string|null>
     */
    public static function forView(): array
    {
        $palette = static::$palette;

        $primary = BrandPalette::firstUsable(
            $palette['brandPrimary'] ?? null,
            BrandPalette::DEFAULT_PRIMARY
        );
        $secondary = BrandPalette::firstUsable(
            $palette['brandSecondary'] ?? null,
            BrandPalette::DEFAULT_SECONDARY
        );

        return [
            'brandPrimary' => $primary,
            'brandSecondary' => $secondary,
            'brandOnPrimary' => $palette['brandOnPrimary'] ?? BrandPalette::onColor($primary),
            'brandOnSecondary' => $palette['brandOnSecondary'] ?? BrandPalette::onColor($secondary),
            'brandPrimaryInk' => $palette['brandPrimaryInk'] ?? BrandPalette::ink($primary),
            'brandPrimarySubtle' => $palette['brandPrimarySubtle'] ?? BrandPalette::subtle($primary),
            'siteSlogan' => $palette['siteSlogan'] ?? null,
        ];
    }
}
