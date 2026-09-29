<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US Employer Identification Number: nine digits, written XX-XXXXXXX.
 *
 * NINE DIGITS is the number. The hyphen after the first two is how an EIN is
 * written down, not part of it - the same way a phone number's brackets are not
 * part of the phone number. Three MicroBilt strategies already treat it that
 * way, each reducing the stored value to digits before sending it on:
 *
 *     preg_replace('/[^0-9]/', '', $borrower->business_ein ?? '')
 *
 * This rule used to demand the written form, `^\d{2}-\d{7}$`, and the borrower
 * profile did not use it at all - it carried a bare `size:10`, which is the same
 * demand by another name. The portal formats the field as the borrower types and
 * then posts the nine digits, exactly as the data model says it should, so
 * saving business details could never succeed: nine characters sent to an
 * endpoint that required ten. The same field's change-request modal does not
 * strip the hyphen, which is why that one path worked and made the failure look
 * intermittent rather than total.
 *
 * So both writings are accepted - they are the same number - and normalize()
 * gives the one form that gets stored.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job: an
 * empty value passes here, so an optional EIN stays optional.
 */
class BusinessEin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('common.business_ein_invalid'));

            return;
        }

        $raw = (string) $value;

        // Anything that is neither a digit nor the separator is not a writing of
        // an EIN, it is a different value - "AB-CDEFGHI" must not pass by having
        // its letters quietly discarded.
        if (preg_match('/[^\d\s-]/', $raw)) {
            $fail(__('common.business_ein_invalid'));

            return;
        }

        if (strlen((string) self::normalize($raw)) !== self::digits()) {
            $fail(__('common.business_ein_invalid'));
        }
    }

    /**
     * The one stored form: the nine digits, with any writing removed.
     *
     * Returns null for a value carrying no digits at all, so an empty EIN stays
     * empty rather than becoming an empty string in the column.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /**
     * How long a written EIN is, so the rule, the forms and any client-side
     * check can never drift apart. Ten: nine digits plus the hyphen.
     */
    public static function length(): int
    {
        return 10;
    }

    /** How many digits an EIN carries - the number itself. */
    public static function digits(): int
    {
        return 9;
    }
}
