<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount of money as a plain number: digits with up to two decimals,
 * e.g. 50000 or 50000.50 (R3-BUG-022). Laravel's `numeric` takes anything
 * PHP's is_numeric() does - "5e4", ".5", " 50000" - none of which is how
 * an amount is written. A leading minus is let through so `min:0` answers
 * a negative amount with its own message; put this rule after `numeric`.
 */
class MoneyAmount implements ValidationRule
{
    private const PATTERN = '/^-?\d+(\.\d{1,2})?$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            return;
        }

        if ((!is_string($value) && !is_float($value)) || !preg_match(self::PATTERN, (string) $value)) {
            $fail(__('borrower_validation.money_amount_format'));
        }
    }
}
