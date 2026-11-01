<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * A date of birth of someone at least the minimum age to apply (18).
 *
 * The embedded form already refused a minor, but the portal's ownership step
 * and co-borrower check took any date up to today, so a 17-year-old owner or
 * co-borrower could be saved (R2 BUG-031). A contract signed by a minor is not
 * enforceable. The age is the embedded form's, so the two can never disagree.
 *
 * Whether the value is a date at all, or may be left out, is still `date` /
 * `required` / `nullable`'s job - an empty or unparseable value passes here.
 */
class AdultDateOfBirth implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $born = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($born->greaterThan(self::latestDate())) {
            $fail(__('common.date_of_birth_minimum_age', ['age' => self::minimumAge()]));
        }
    }

    public static function minimumAge(): int
    {
        return (int) config('funding_application.embed_form.minimum_age', 18);
    }

    /** The latest date of birth that is old enough today. */
    public static function latestDate(): Carbon
    {
        return now()->startOfDay()->subYears(self::minimumAge());
    }
}
