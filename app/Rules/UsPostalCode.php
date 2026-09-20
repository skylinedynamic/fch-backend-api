<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US ZIP code: exactly five digits, nothing else.
 *
 * Zip fields used to be plain `string|max:20` (and in one case `max:255`), so
 * "N1 2AB", "12345-6789" and "abcde" were all stored as zip codes and the forms
 * disagreed with each other about what was acceptable. This rule is the single
 * definition both portals and the embedded form validate against.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
 * empty value passes here, so an optional zip stays optional.
 */
class UsPostalCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) && !is_numeric($value)) {
            $fail(__('common.postal_code_invalid'));

            return;
        }

        if (!preg_match('/^\d{' . self::length() . '}$/', (string) $value)) {
            $fail(__('common.postal_code_invalid'));
        }
    }

    /**
     * How many digits a zip code has, so the rule, the embedded form's markup
     * and its client-side check can never drift apart.
     */
    public static function length(): int
    {
        return (int) config('funding_application.postal_code_length', 5);
    }
}
