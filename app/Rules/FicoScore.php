<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A FICO score: a whole number from 300 to 850.
 *
 * The borrower's own score and every FICO threshold compared against it
 * (a lender's min_fico, a credit account's typical minimum, a capital-access
 * requirement) were each checked their own way - `numeric`, `min:0`, digits
 * only, or 300-850 on one path of a request and `min:0` on the other - so a
 * client could be saved with a FICO of 9000 and a credit account with a
 * minimum of 0. This rule is the single definition.
 *
 * The bounds are funding_application.credit_score, which the embedded form and
 * the portal's score bands already read.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
 * empty value passes here, so an optional score stays optional.
 */
class FicoScore implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $score = self::wholeNumber($value);

        if ($score === null || $score < self::min() || $score > self::max()) {
            $fail(__('common.fico_score_invalid', ['min' => self::min(), 'max' => self::max()]));
        }
    }

    public static function min(): int
    {
        return (int) config('funding_application.credit_score.min', 300);
    }

    public static function max(): int
    {
        return (int) config('funding_application.credit_score.max', 850);
    }

    /**
     * The value as an integer when it is one - 720, "720", 720.0, "720.00" (a
     * decimal column, e.g. minimum_fico_requirement, echoes back that way) - and
     * null for anything else ("720.5", "abc", "7e2", an array).
     */
    public static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : null;
        }

        if (is_string($value) && preg_match('/^\s*(-?\d+)(\.0+)?\s*$/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
