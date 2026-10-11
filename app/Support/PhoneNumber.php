<?php

namespace App\Support;

/**
 * Phone numbers are stored as people type them; SMS providers need E.164
 * (+15551234567). Converted at send time only - stored values are untouched.
 *
 * A country's dialling code and national number length come from
 * config/locations.php (`phone_code`, `phone_national_length`).
 */
final class PhoneNumber
{
    private const E164_MIN_DIGITS = 8;
    private const E164_MAX_DIGITS = 15;
    private const VISIBLE_DIGITS = 4;

    /**
     * The number in E.164, or null when it cannot be one.
     *
     * A number written with a leading "+" is taken as already international.
     * Otherwise it is read as a number of $countryCode (a locations code such
     * as USA): the national number alone, or prefixed with that country's
     * dialling code.
     */
    public static function toE164(?string $raw, string $countryCode): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with($raw, '+')) {
            $length = strlen($digits);

            return $length >= self::E164_MIN_DIGITS && $length <= self::E164_MAX_DIGITS ? '+' . $digits : null;
        }

        $format = self::formatOf($countryCode);

        if ($format === null) {
            return null;
        }

        [$dialCode, $nationalLength] = $format;

        if (strlen($digits) === $nationalLength) {
            return '+' . $dialCode . $digits;
        }

        if (strlen($digits) === strlen($dialCode) + $nationalLength && str_starts_with($digits, $dialCode)) {
            return '+' . $digits;
        }

        return null;
    }

    /**
     * A regex matching this country's numbers in E.164, or null when the
     * country has no phone format in config/locations.php.
     */
    public static function e164Pattern(string $countryCode): ?string
    {
        $format = self::formatOf($countryCode);

        if ($format === null) {
            return null;
        }

        [$dialCode, $nationalLength] = $format;

        return '/^\+' . preg_quote($dialCode, '/') . '\d{' . $nationalLength . '}$/';
    }

    /**
     * Only the last four digits, for logs.
     */
    public static function mask(?string $number): string
    {
        $compact = preg_replace('/[^\d+]/', '', (string) $number);

        if ($compact === '') {
            return '';
        }

        return str_repeat('*', max(0, strlen($compact) - self::VISIBLE_DIGITS)) . substr($compact, -self::VISIBLE_DIGITS);
    }

    /**
     * @return array{0: string, 1: int}|null The dialling code digits and national number length
     */
    private static function formatOf(string $countryCode): ?array
    {
        $country = Locations::map()[$countryCode] ?? null;
        $dialCode = preg_replace('/\D/', '', (string) ($country['phone_code'] ?? ''));
        $nationalLength = $country['phone_national_length'] ?? null;

        return $dialCode !== '' && is_int($nationalLength) ? [$dialCode, $nationalLength] : null;
    }
}
