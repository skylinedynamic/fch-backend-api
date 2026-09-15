<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number that normalises to a valid US number.
 *
 * The public form used to take anything at all, so "1234567890" was accepted as
 * a phone number. This defers to normalizePhoneE164(), which applies the NANP
 * rules - ten digits, and neither the area code nor the exchange starting with
 * 0 or 1 - so the rule and the stored value can never disagree about what counts
 * as valid.
 */
class UsPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('embed_form.phone_invalid'));
            return;
        }

        if (normalizePhoneE164((string) $value) === null) {
            $fail(__('embed_form.phone_invalid'));
        }
    }
}
