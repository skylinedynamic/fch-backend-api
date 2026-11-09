<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A date of birth anywhere on the platform: a real calendar date, not in the
 * future, and at least the minimum age (config `app.minimum_age`, 18).
 *
 * Every request that takes a date of birth uses this one rule, so the same
 * person is accepted or refused the same way on every screen. Before it, most
 * requests took `date` (which accepts "next tuesday" and rolls 2000-02-30 over
 * into March), some added `before:today`, and only the embed form had an age
 * floor.
 *
 * Age is worked out from the full date: someone born 18 years ago today is 18
 * and may continue. The cutoff is "today minus 18 years" without month overflow,
 * so on 29 Feb 2028 the cutoff is 28 Feb 2010, and someone born on 29 Feb turns
 * 18 on 1 March in a common year. "Today" is the application's timezone.
 *
 * Accepted writing: YYYY-MM-DD, which is what every date picker posts. The
 * admin client form posts MM-DD-YYYY (normalizeDate() stores it), so that
 * request uses acceptingUsFormat(). Dates before 1900 are refused as not a real
 * date of birth.
 *
 * This is the platform's minimum age only. Legal capacity rules for a state or
 * a funding product are checked separately.
 *
 * Whether the field may be left out is `required` / `nullable`'s job - an empty
 * value passes here.
 */
class DateOfBirth implements ValidationRule
{
    public const DEFAULT_MINIMUM_AGE = 18;

    public const EARLIEST = '1900-01-01';

    public function __construct(private bool $usFormat = false) {}

    /** Also takes MM-DD-YYYY and MM/DD/YYYY (month first). */
    public static function acceptingUsFormat(): self
    {
        return new self(true);
    }

    public static function minimumAge(): int
    {
        return (int) config('app.minimum_age', self::DEFAULT_MINIMUM_AGE);
    }

    /** The latest date of birth that is old enough today - the date pickers' max date. */
    public static function latestAllowedDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->subYearsNoOverflow(self::minimumAge());
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $date = self::parse($value, $this->usFormat);

        if ($date === null || $date->lt(CarbonImmutable::parse(self::EARLIEST))) {
            $fail(__('common.date_of_birth_invalid'));

            return;
        }

        if ($date->gt(CarbonImmutable::today())) {
            $fail(__('common.date_of_birth_future'));

            return;
        }

        if ($date->gt(self::latestAllowedDate())) {
            $fail(__('common.date_of_birth_minimum_age', ['age' => self::minimumAge()]));
        }
    }

    /** The calendar date written, or null when it is not one. */
    public static function parse(mixed $value, bool $usFormat = false): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif ($usFormat && preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
