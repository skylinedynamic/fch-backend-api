<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * A borrower's date of birth: an adult, and not past a human lifespan.
 *
 * The admin's Add/Edit Client took any date of birth (BUG-005: 17 and 106 years
 * old were both saved) and the borrower's own profile only checked it was in the
 * past. A borrower signs funding agreements, so this is the single check, with
 * the bounds in borrower.date_of_birth.
 *
 * Takes YYYY-MM-DD (the portals' profile forms) and MM-DD-YYYY or MM/DD/YYYY
 * (the admin client form, read the same way normalizeDate() stores it). An empty
 * value passes - whether the field may be left out is `required` / `nullable`'s
 * job.
 */
class AdultDateOfBirth implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $dob = self::parse($value);

        if ($dob === null) {
            $fail(__('common.date_of_birth_invalid'));
            return;
        }

        if ($dob->greaterThan(Carbon::today()->subYears(self::minAge()))) {
            $fail(__('common.date_of_birth_min_age', ['age' => self::minAge()]));
            return;
        }

        // Still 120 the day before the 121st birthday.
        if ($dob->lessThanOrEqualTo(Carbon::today()->subYears(self::maxAge() + 1))) {
            $fail(__('common.date_of_birth_max_age', ['age' => self::maxAge()]));
        }
    }

    public static function minAge(): int
    {
        return (int) config('borrower.date_of_birth.min_age', 18);
    }

    public static function maxAge(): int
    {
        return (int) config('borrower.date_of_birth.max_age', 120);
    }

    private static function parse(mixed $value): ?Carbon
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            [, $year, $month, $day] = $m;
        } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            [, $month, $day, $year] = $m;
        } else {
            return null;
        }

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return Carbon::create((int) $year, (int) $month, (int) $day)->startOfDay();
    }
}
