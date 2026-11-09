<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * A date that is today or earlier - e.g. a business start date (BUG-007).
 *
 * The admin's Update Client saved a business start date in the future and said
 * success, but its date picker cannot show a date after today, so the field
 * read as empty after reload and the next save cleared it. Refusing the date
 * up front tells the user instead.
 *
 * Read the way normalizeDate() stores it, so YYYY-MM-DD (the portals' profile
 * forms) and MM-DD-YYYY or MM/DD/YYYY (the admin client form) all work. An empty
 * or unreadable value passes: `required`, `nullable` and `date` decide those.
 */
class NotFutureDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $date = normalizeDate(trim($value));

        if ($date !== null && Carbon::parse($date)->startOfDay()->greaterThan(Carbon::today())) {
            $fail(__('common.date_not_in_future'));
        }
    }
}
