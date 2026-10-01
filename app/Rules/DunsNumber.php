<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A D-U-N-S number: exactly nine digits, nothing else.
 *
 * No dashes and no spaces - unlike an EIN it is never written grouped, which is
 * why the admin screen's input mask strips everything but digits and stops at
 * nine.
 *
 * The admin create and update paths had it as `string|max:255`, so a client
 * could be saved with an eight-digit DUNS or one carrying letters, while the
 * borrower portal enforced a length. This rule is the single definition every
 * surface validates against.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
 * empty value passes here, so an optional DUNS stays optional.
 */
class DunsNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('common.duns_number_invalid'));

            return;
        }

        if (!preg_match('/^\d{' . self::length() . '}$/', (string) $value)) {
            $fail(__('common.duns_number_invalid'));
        }
    }

    /**
     * How many digits a DUNS number has, so the rule, the forms and any
     * client-side check can never drift apart.
     */
    public static function length(): int
    {
        return 9;
    }
}
