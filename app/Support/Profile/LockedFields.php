<?php

namespace App\Support\Profile;

use Carbon\Carbon;
use Throwable;

/**
 * The server side of the profile's field locks (BUG-054). A locked field that
 * already holds a value may only be sent back unchanged; filling in an empty
 * one is allowed. "Unchanged" ignores how a value is written - an EIN with or
 * without its hyphen, a date with a time, an email in other case - because the
 * form sends every field back as it shows it.
 */
class LockedFields
{
    /** Compared by their digits only. */
    private const DIGIT_FIELDS = ['business_ein', 'business_phone', 'duns_number'];

    /** Compared as calendar dates. */
    private const DATE_FIELDS = ['business_start_date', 'account_open_date'];

    /** Compared as amounts. */
    private const AMOUNT_FIELDS = ['monthly_revenue', 'annual_revenue', 'annual_personal_income'];

    public static function isSaved(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * The locked fields in $incoming that would change a saved value.
     *
     * @param  array<string, mixed>  $saved     column => stored value
     * @param  array<string, mixed>  $incoming  field => value sent
     * @param  string[]              $locked
     * @return string[]
     */
    public static function changed(array $saved, array $incoming, array $locked): array
    {
        $changed = [];

        foreach ($locked as $field) {
            if (!array_key_exists($field, $incoming) || !self::isSaved($saved[$field] ?? null)) {
                continue;
            }

            if (self::normalise($field, $incoming[$field]) !== self::normalise($field, $saved[$field])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function normalise(string $field, mixed $value): string
    {
        if (!self::isSaved($value)) {
            return '';
        }

        if (in_array($field, self::DIGIT_FIELDS, true)) {
            return preg_replace('/\D/', '', (string) $value);
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (Throwable) {
                return trim((string) $value);
            }
        }

        if (in_array($field, self::AMOUNT_FIELDS, true) && is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return mb_strtolower(trim((string) $value));
    }
}
