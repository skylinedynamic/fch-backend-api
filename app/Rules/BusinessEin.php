<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US Employer Identification Number: nine digits written XX-XXXXXXX.
 *
 * Nine digits, so ten characters once the dash is written in - which is how the
 * borrower portal already described it ("exactly 10 characters (XX-XXXXXXX)")
 * and what the admin screen's own input mask produces as you type.
 *
 * The admin create and update paths had it as `string|max:255`, so a client
 * could be saved with a three-digit EIN, or with one carrying letters, while the
 * borrower portal refused the same value. This rule is the single definition
 * every surface validates against.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
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

        if (!preg_match('/^\d{2}-\d{7}$/', (string) $value)) {
            $fail(__('common.business_ein_invalid'));
        }
    }

    /**
     * How long a written EIN is, so the rule, the forms and any client-side
     * check can never drift apart. Ten: nine digits plus the dash.
     */
    public static function length(): int
    {
        return 10;
    }

    /** How many digits an EIN carries, ignoring the dash. */
    public static function digits(): int
    {
        return 9;
    }
}
