<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US bank routing (ABA) number: exactly nine digits, nothing else.
 *
 * Checked as text, never as a number: routing numbers start with 0 or 1, and a
 * numeric check would quietly lose the leading zero.
 */
class RoutingNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('common.routing_number_invalid'));

            return;
        }

        if (!preg_match('/^\d{' . self::length() . '}$/', (string) $value)) {
            $fail(__('common.routing_number_invalid'));
        }
    }

    /** How many digits a routing number has. */
    public static function length(): int
    {
        return 9;
    }
}
