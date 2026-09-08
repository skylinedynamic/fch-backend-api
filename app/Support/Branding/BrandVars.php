<?php

namespace App\Support\Branding;

/**
 * The CSS custom properties a tenant's brand publishes on :root.
 *
 * MIRROR: buildBrandVars() in react-fch/src/utils/brandingTheme.ts (and its copy
 * in borrower-fch). The map is generated here so a cold first visit - no
 * localStorage yet - can be painted by a render-blocking stylesheet instead of
 * flashing the default brand until JS runs. The portals apply this map verbatim
 * rather than deriving their own, so there is one source of truth.
 *
 * Careful when comparing with the TypeScript: its contrastColor() defaults to
 * SIDEBAR_LIGHT_TEXT/SIDEBAR_DARK_TEXT while BrandPalette::onColor() defaults to
 * ON_BRAND_LIGHT/ON_BRAND_DARK. Every call below passes both explicitly so the
 * two implementations cannot disagree through their defaults.
 */
class BrandVars
{
    /** Resting sidebar label colour the theme shipped with. */
    public const SIDEBAR_LIGHT_TEXT = '#D3D8E5';
    public const SIDEBAR_DARK_TEXT = '#1B1F2B';

    /** The top bar shipped white with grey text; unset settings reproduce that. */
    public const DEFAULT_TOPBAR_BG = '#FFFFFF';
    public const TOPBAR_DARK_TEXT = '#495057';

    /** Bump when the map's shape changes so stale persisted blobs are ignored. */
    public const VERSION = 1;

    /** "r, g, b" for the rgba() forms the stylesheets use. */
    private static function rgbString(string $color): string
    {
        $rgb = BrandPalette::toRgb($color);

        return $rgb ? \implode(', ', $rgb) : '0, 0, 0';
    }

    /** The sidebar canvas: its own setting when present, otherwise the brand colour. */
    public static function sidebarBg(array $branding): string
    {
        return BrandPalette::firstUsable(
            $branding['sidebar_bg_color'] ?? null,
            $branding['primary_color'] ?? null,
            BrandPalette::DEFAULT_PRIMARY
        );
    }

    /**
     * Every colour the sidebar menu needs, each unset setting derived from the
     * ones above it.
     */
    public static function sidebarColors(array $branding): array
    {
        $sidebarBg = self::sidebarBg($branding);

        $text = BrandPalette::firstUsable(
            $branding['sidebar_text_color'] ?? null,
            BrandPalette::onColor($sidebarBg, self::SIDEBAR_LIGHT_TEXT, self::SIDEBAR_DARK_TEXT)
        );

        $icon = BrandPalette::firstUsable($branding['sidebar_icon_color'] ?? null, $text);

        $hoverBg = BrandPalette::firstUsable(
            $branding['sidebar_hover_bg_color'] ?? null,
            BrandPalette::mix($sidebarBg, $text, 0.12)
        );

        // Derived against the hover background, not the canvas, so a light hover
        // colour flips the label instead of leaving it unreadable.
        $textHover = BrandPalette::firstUsable(
            $branding['sidebar_text_hover_color'] ?? null,
            BrandPalette::onColor($hoverBg, $text, self::SIDEBAR_DARK_TEXT)
        );

        $activeBg = BrandPalette::firstUsable(
            $branding['sidebar_active_bg_color'] ?? null,
            $branding['secondary_color'] ?? null,
            BrandPalette::DEFAULT_SECONDARY
        );

        $active = BrandPalette::firstUsable(
            $branding['sidebar_active_text_color'] ?? null,
            BrandPalette::onColor($activeBg, BrandPalette::ON_BRAND_LIGHT, BrandPalette::ON_BRAND_DARK)
        );

        return [
            'text' => $text,
            'icon' => $icon,
            'hoverBg' => $hoverBg,
            'textHover' => $textHover,
            'iconHover' => BrandPalette::firstUsable($branding['sidebar_icon_hover_color'] ?? null, $textHover),
            'activeBg' => $activeBg,
            'active' => $active,
            // Follows the selected label unless set, the same way the resting icon
            // follows the resting label.
            'activeIcon' => BrandPalette::firstUsable($branding['sidebar_active_icon_color'] ?? null, $active),
        ];
    }

    /**
     * A button's fill, label and hover shade, all from one brand colour.
     *
     * Buttons deliberately have no settings of their own: the main button is the
     * main colour and the secondary button is the accent.
     */
    public static function buttonColors(string $base): array
    {
        return [
            'bg' => $base,
            'text' => BrandPalette::onColor($base, BrandPalette::ON_BRAND_LIGHT, BrandPalette::ON_BRAND_DARK),
            'hoverBg' => BrandPalette::hover($base),
        ];
    }

    /** The top bar canvas and the text and icons sitting on it. */
    public static function topbarColors(array $branding): array
    {
        $bg = BrandPalette::firstUsable($branding['topbar_bg_color'] ?? null, self::DEFAULT_TOPBAR_BG);

        return [
            'bg' => $bg,
            'text' => BrandPalette::firstUsable(
                $branding['topbar_text_color'] ?? null,
                BrandPalette::onColor($bg, BrandPalette::ON_BRAND_LIGHT, self::TOPBAR_DARK_TEXT)
            ),
        ];
    }

    /** Every CSS custom property the brand stylesheets read, as a flat map. */
    public static function build(?array $branding): array
    {
        $branding ??= [];

        $primary = BrandPalette::firstUsable($branding['primary_color'] ?? null, BrandPalette::DEFAULT_PRIMARY);
        $secondary = BrandPalette::firstUsable($branding['secondary_color'] ?? null, BrandPalette::DEFAULT_SECONDARY);

        $sidebarBg = self::sidebarBg($branding);
        $sidebar = self::sidebarColors($branding);

        $onPrimary = BrandPalette::onColor($primary, BrandPalette::ON_BRAND_LIGHT, BrandPalette::ON_BRAND_DARK);
        $onSecondary = BrandPalette::onColor($secondary, BrandPalette::ON_BRAND_LIGHT, BrandPalette::ON_BRAND_DARK);

        $primaryButton = self::buttonColors($primary);
        $secondaryButton = self::buttonColors($secondary);
        $topbar = self::topbarColors($branding);

        return [
            '--brand-topbar-bg' => $topbar['bg'],
            '--brand-topbar-bg-rgb' => self::rgbString($topbar['bg']),
            '--brand-topbar-color' => $topbar['text'],
            '--brand-topbar-color-rgb' => self::rgbString($topbar['text']),

            '--brand-btn-primary-bg' => $primaryButton['bg'],
            '--brand-btn-primary-rgb' => self::rgbString($primaryButton['bg']),
            '--brand-btn-primary-color' => $primaryButton['text'],
            '--brand-btn-primary-hover-bg' => $primaryButton['hoverBg'],

            '--brand-btn-secondary-bg' => $secondaryButton['bg'],
            '--brand-btn-secondary-rgb' => self::rgbString($secondaryButton['bg']),
            '--brand-btn-secondary-color' => $secondaryButton['text'],
            '--brand-btn-secondary-hover-bg' => $secondaryButton['hoverBg'],

            '--brand-primary' => $primary,
            '--brand-primary-rgb' => self::rgbString($primary),
            '--brand-primary-hover' => BrandPalette::hover($primary),
            '--brand-primary-ink' => BrandPalette::ink($primary),
            '--brand-primary-subtle' => BrandPalette::subtle($primary),
            '--brand-on-primary' => $onPrimary,
            '--brand-on-primary-rgb' => self::rgbString($onPrimary),

            '--brand-secondary' => $secondary,
            '--brand-secondary-rgb' => self::rgbString($secondary),
            '--brand-secondary-hover' => BrandPalette::hover($secondary),
            '--brand-secondary-ink' => BrandPalette::ink($secondary),
            '--brand-secondary-subtle' => BrandPalette::subtle($secondary),
            '--brand-on-secondary' => $onSecondary,

            '--brand-sidebar-bg' => $sidebarBg,
            '--brand-sidebar-bg-rgb' => self::rgbString($sidebarBg),
            '--brand-on-sidebar' => $sidebar['text'],
            '--brand-on-sidebar-rgb' => self::rgbString($sidebar['text']),
            '--sidebar-hover-bg' => $sidebar['hoverBg'],
            '--sidebar-active-bg' => $sidebar['activeBg'],

            // Names the existing stylesheets already use.
            '--primary-color' => $primary,
            '--secondary-color' => $secondary,
            '--sidebar-text-color' => $sidebar['text'],
            '--sidebar-icon-color' => $sidebar['icon'],
            '--sidebar-text-hover-color' => $sidebar['textHover'],
            '--sidebar-icon-hover-color' => $sidebar['iconHover'],
            '--sidebar-active-text-color' => $sidebar['active'],
            '--sidebar-active-icon-color' => $sidebar['activeIcon'],
            '--sidebar-text-color-rgb' => self::rgbString($sidebar['text']),
        ];
    }

    /**
     * The map as a stylesheet.
     *
     * The selector is `:root:root`, not `:root`, and that is load-bearing.
     *
     * This sheet is injected by the boot script at the top of <head>, so it lands
     * BEFORE the app's own bundle - which ships brand-tokens.css and its default
     * `:root{--brand-primary:#001F3F}` cold-load fallbacks. At equal specificity
     * the later rule wins, so a plain `:root` here would be overridden by the
     * packaged default and the tenant would still see the wrong brand on a cold
     * load. Repeating the selector doubles specificity (0,2,0 against 0,1,0) so
     * this wins regardless of document order.
     *
     * Inline styles on the element still outrank it, which is intended: a repeat
     * visitor's cached map is applied inline by the boot script and paints
     * instantly, and applyBrandTheme() refreshes it once the API answers.
     *
     * Velzon reads --vz-primary with !important in custom.css, so those two are
     * emitted with matching priority - exactly as applyBrandTheme() does.
     */
    public static function toCss(?array $branding): string
    {
        $vars = self::build($branding);

        $lines = [];
        foreach ($vars as $name => $value) {
            $lines[] = \sprintf('  %s: %s;', $name, $value);
        }

        $lines[] = \sprintf('  --vz-primary: %s !important;', $vars['--brand-primary']);
        $lines[] = \sprintf('  --vz-primary-rgb: %s !important;', $vars['--brand-primary-rgb']);

        return ":root:root {\n" . \implode("\n", $lines) . "\n}\n";
    }
}
