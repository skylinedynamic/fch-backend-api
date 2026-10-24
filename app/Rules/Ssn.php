<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US Social Security Number: nine digits, written XXX-XX-XXXX.
 *
 * As with BusinessEin, the hyphens are how the number is written down rather
 * than part of it, so both writings are the same SSN and normalize() gives the
 * one form that gets stored.
 *
 * The credit profile request - the form that actually triggers a credit pull -
 * validated this as `required|string|max:255`, which accepts "abc". That is the
 * field an FCRA pull is keyed on, and its only check was that the borrower had
 * typed something. Elsewhere the same value was `digits:9` in two requests and
 * `size:4` in two others, so no two surfaces agreed on what an SSN was.
 *
 * The SSA's published exclusions are enforced, because they are what separates a
 * real SSN from any nine digits:
 *
 *   area   (first three)  - never 000, never 666, never 900-999
 *   group  (middle two)   - never 00
 *   serial (last four)    - never 0000
 *
 * 123-45-6789 is the textbook example and stays valid: it is well-formed, and
 * refusing it would be this rule inventing policy rather than applying it.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
 * empty value passes here, so an optional SSN stays optional.
 */
class Ssn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('common.ssn_invalid'));

            return;
        }

        $raw = trim((string) $value);

        // Anything that is neither a digit nor a separator is a different value,
        // not a writing of an SSN - "abc-de-fghi" must not pass by having its
        // letters quietly discarded.
        if (preg_match('/[^\d\s-]/', $raw)) {
            $fail(__('common.ssn_invalid'));

            return;
        }

        $digits = self::normalize($raw);

        if ($digits === null || strlen($digits) !== self::digits()) {
            $fail(__('common.ssn_invalid'));

            return;
        }

        if (!self::isIssuable($digits)) {
            $fail(__('common.ssn_invalid'));
        }
    }

    /**
     * Whether these nine digits are a combination the SSA actually issues.
     */
    private static function isIssuable(string $digits): bool
    {
        $area = substr($digits, 0, 3);
        $group = substr($digits, 3, 2);
        $serial = substr($digits, 5, 4);

        if ($area === '000' || $area === '666' || (int) $area >= 900) {
            return false;
        }

        return $group !== '00' && $serial !== '0000';
    }

    /**
     * The one stored form: the nine digits, with any writing removed.
     *
     * Returns null for a value carrying no digits at all, so an empty SSN stays
     * empty rather than becoming an empty string in the column.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || (!is_string($value) && !is_numeric($value))) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /**
     * What a request should hand this rule: a writing of an SSN reduced to its
     * nine digits, so the column holds one form - and anything else exactly as
     * sent, so the rule still sees the letters normalize() would strip.
     */
    public static function canonical(mixed $value): mixed
    {
        if (!is_string($value) || !preg_match('/^[\d\s-]+$/', $value)) {
            return $value;
        }

        return self::normalize($value);
    }

    /**
     * The number as it may be echoed back: last four only.
     *
     * Here rather than at each call site, so nowhere has to decide for itself
     * how much of an SSN is safe to show.
     */
    public static function mask(mixed $value): ?string
    {
        $digits = self::normalize($value);

        return $digits === null ? null : '***-**-' . substr($digits, -4);
    }

    /**
     * How long a written SSN is, so the rule, the forms and any client-side
     * check can never drift apart. Eleven: nine digits plus two hyphens.
     */
    public static function length(): int
    {
        return 11;
    }

    /** How many digits an SSN carries - the number itself. */
    public static function digits(): int
    {
        return 9;
    }
}
