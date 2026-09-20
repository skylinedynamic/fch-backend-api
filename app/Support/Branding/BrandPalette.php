<?php

namespace App\Support\Branding;

/**
 * Brand colour maths.
 *
 * MIRROR: a line-for-line port of react-fch/src/utils/brandingTheme.ts (and its
 * copy in borrower-fch). Blade-rendered mail and PDFs cannot run JS, so the same
 * derivations have to exist here or a tenant's email would not match their portal.
 * If you change one, change all three.
 *
 * The distinction that matters:
 *
 *   onColor()  text/icon colour that contrasts with X   (for text ON the brand colour)
 *   ink()      X darkened until it is legible ON WHITE  (for brand-coloured text)
 *
 * They answer different questions, and conflating them is why light brand colours
 * used to break in two separate ways.
 */
class BrandPalette
{
    public const ON_BRAND_LIGHT = '#FFFFFF';
    public const ON_BRAND_DARK = '#1B1F2B';

    public const DEFAULT_PRIMARY = '#001F3F';
    public const DEFAULT_SECONDARY = '#D4AF37';

    /** Minimum WCAG AA ratio for body text. */
    public const AA_CONTRAST = 4.5;

    /** Whether a stored value is a colour rather than an empty or "null" string. */
    public static function usable(?string $value): bool
    {
        $value = \trim((string) $value);

        return $value !== '' && $value !== 'null' && $value !== 'undefined';
    }

    /** @return array{0:int,1:int,2:int}|null */
    public static function toRgb(?string $hex): ?array
    {
        if (!self::usable($hex)) {
            return null;
        }

        $value = \ltrim(\trim((string) $hex), '#');

        if (\preg_match('/^[0-9a-f]{3}$/i', $value)) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        if (!\preg_match('/^[0-9a-f]{6}$/i', $value)) {
            return null;
        }

        return [
            \hexdec(\substr($value, 0, 2)),
            \hexdec(\substr($value, 2, 2)),
            \hexdec(\substr($value, 4, 2)),
        ];
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public static function toHex(array $rgb): string
    {
        $hex = '#';
        foreach ($rgb as $channel) {
            $clamped = (int) \round(\max(0, \min(255, $channel)));
            $hex .= \str_pad(\dechex($clamped), 2, '0', STR_PAD_LEFT);
        }

        return \strtoupper($hex);
    }

    /** WCAG relative luminance, 0 for black through 1 for white. */
    public static function luminance(array $rgb): float
    {
        $channels = [];
        foreach ($rgb as $channel) {
            $c = $channel / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : \pow(($c + 0.055) / 1.055, 2.4);
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** WCAG contrast ratio, 1 (identical) to 21 (black on white). */
    public static function contrastRatio(?string $a, ?string $b): float
    {
        $rgbA = self::toRgb($a);
        $rgbB = self::toRgb($b);

        if ($rgbA === null || $rgbB === null) {
            return 1.0;
        }

        $lumA = self::luminance($rgbA);
        $lumB = self::luminance($rgbB);

        return (\max($lumA, $lumB) + 0.05) / (\min($lumA, $lumB) + 0.05);
    }

    /** Blend $color toward $target; $weight is how much of $target to take. */
    public static function mix(string $color, string $target, float $weight): string
    {
        $from = self::toRgb($color);
        $to = self::toRgb($target);

        if ($from === null || $to === null) {
            return $color;
        }

        $w = \max(0.0, \min(1.0, $weight));

        return self::toHex([
            $from[0] + ($to[0] - $from[0]) * $w,
            $from[1] + ($to[1] - $from[1]) * $w,
            $from[2] + ($to[2] - $from[2]) * $w,
        ]);
    }

    /**
     * Whichever of $light/$dark contrasts better against $background.
     *
     * The soft defaults read better in the common case but cannot clear AA against
     * a mid-tone background, so we escalate to pure black or white rather than
     * knowingly return an unreadable colour.
     */
    public static function onColor(
        ?string $background,
        string $light = self::ON_BRAND_LIGHT,
        string $dark = self::ON_BRAND_DARK
    ): string {
        if (self::toRgb($background) === null) {
            return $light;
        }

        $preferred = self::contrastRatio($background, $light) >= self::contrastRatio($background, $dark)
            ? $light
            : $dark;

        if (self::contrastRatio($background, $preferred) >= self::AA_CONTRAST) {
            return $preferred;
        }

        return self::contrastRatio($background, '#000000') >= self::contrastRatio($background, '#FFFFFF')
            ? '#000000'
            : '#FFFFFF';
    }

    /**
     * $color darkened until it clears AA against white.
     *
     * This is the colour for brand-coloured TEXT on a white page, which a light
     * brand colour would otherwise render invisible.
     */
    public static function ink(?string $color, string $onBackground = '#FFFFFF'): string
    {
        if (!self::usable($color) || self::toRgb($color) === null) {
            return self::ON_BRAND_DARK;
        }

        $base = \trim((string) $color);

        if (self::contrastRatio($base, $onBackground) >= self::AA_CONTRAST) {
            return $base;
        }

        // 4% steps toward black; 20 steps (80%) is enough for even pure yellow.
        for ($step = 1; $step <= 20; $step++) {
            $candidate = self::mix($base, '#000000', $step * 0.04);
            if (self::contrastRatio($candidate, $onBackground) >= self::AA_CONTRAST) {
                return $candidate;
            }
        }

        return self::ON_BRAND_DARK;
    }

    /** A hover shade with room to move: darken a light colour, lighten a dark one. */
    public static function hover(?string $color): string
    {
        $rgb = self::toRgb($color);

        if ($rgb === null) {
            return self::DEFAULT_PRIMARY;
        }

        $target = self::luminance($rgb) > 0.5 ? '#000000' : '#FFFFFF';

        return self::mix(\trim((string) $color), $target, 0.12);
    }

    /** A washed-out tint of $color, for subtle backgrounds. */
    public static function subtle(?string $color): string
    {
        return self::toRgb($color) !== null
            ? self::mix(\trim((string) $color), '#FFFFFF', 0.88)
            : '#F3F3F9';
    }

    /** First usable value, or the last one as the default. */
    public static function firstUsable(?string ...$values): string
    {
        foreach ($values as $value) {
            if (self::usable($value)) {
                return \trim((string) $value);
            }
        }

        return self::DEFAULT_PRIMARY;
    }
}
