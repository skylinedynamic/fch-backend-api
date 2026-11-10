<?php

namespace Tests\Unit\Rules;

use App\Rules\DateOfBirth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform's one date-of-birth rule: a real calendar date, not in the
 * future, and at least the minimum age (18) by the full date of birth.
 */
#[CoversClass(DateOfBirth::class)]
class DateOfBirthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The first error for the value, or null when it passes. */
    private function errorFor(mixed $value, ?DateOfBirth $rule = null): ?string
    {
        $validator = Validator::make(['dob' => $value], ['dob' => ['nullable', $rule ?? new DateOfBirth]]);

        return $validator->errors()->first('dob') ?: null;
    }

    #[Test]
    public function the_minimum_age_is_eighteen(): void
    {
        $this->assertSame(18, DateOfBirth::minimumAge());
    }

    #[Test]
    public function someone_turning_eighteen_today_is_allowed(): void
    {
        $this->assertNull($this->errorFor('2008-11-09'));
    }

    #[Test]
    public function someone_turning_eighteen_tomorrow_is_refused(): void
    {
        $this->assertSame('You must be at least 18 years old to continue.', $this->errorFor('2008-11-10'));
    }

    #[Test]
    public function an_adult_is_allowed(): void
    {
        $this->assertNull($this->errorFor('1985-04-12'));
    }

    #[Test]
    public function a_date_of_birth_of_today_is_under_age_not_in_the_future(): void
    {
        $this->assertSame(__('common.date_of_birth_minimum_age', ['age' => 18]), $this->errorFor('2026-11-09'));
    }

    #[Test]
    public function a_future_date_is_refused_as_in_the_future(): void
    {
        $this->assertSame(__('common.date_of_birth_future'), $this->errorFor('2026-11-10'));
        $this->assertSame(__('common.date_of_birth_future'), $this->errorFor('2090-01-01'));
    }

    public static function invalidDates(): array
    {
        return [
            'no such day' => ['2000-02-30'],
            'no such month' => ['2000-13-01'],
            'not a leap year' => ['2001-02-29'],
            'words' => ['next tuesday'],
            'letters' => ['abc'],
            'month-first in the strict format' => ['01/15/2000'],
            'two-digit year' => ['00-01-15'],
            'unpadded ISO' => ['2000-1-5'],
            'trailing junk' => ['2000-01-15x'],
            'a number' => [20000115],
            'an array' => [['2000-01-15']],
        ];
    }

    #[Test]
    #[DataProvider('invalidDates')]
    public function an_invalid_date_is_refused(mixed $value): void
    {
        $this->assertSame(__('common.date_of_birth_invalid'), $this->errorFor($value));
    }

    /**
     * BUG-005: past 120 the date is a typo, not a person. Someone is still 120
     * the day before their 121st birthday (today is 2026-11-09 here).
     */
    public static function withinTheMaximumAge(): array
    {
        return [
            '120 today' => ['1906-11-09'],
            '120, a day short of 121' => ['1905-11-10'],
            'QA: 106 years old' => ['1920-01-01'],
        ];
    }

    #[Test]
    #[DataProvider('withinTheMaximumAge')]
    public function an_age_up_to_120_is_accepted(string $date): void
    {
        $this->assertNull($this->errorFor($date));
    }

    public static function pastTheMaximumAge(): array
    {
        return [
            '121 today' => ['1905-11-09'],
            'born 1899' => ['1899-12-31'],
            'born 1800' => ['1800-01-01'],
        ];
    }

    #[Test]
    #[DataProvider('pastTheMaximumAge')]
    public function over_120_is_refused(string $date): void
    {
        $this->assertSame(__('common.date_of_birth_max_age', ['age' => 120]), $this->errorFor($date));
    }

    #[Test]
    public function the_maximum_age_comes_from_config(): void
    {
        config(['app.maximum_age' => 100]);

        $this->assertSame(100, DateOfBirth::maximumAge());
        $this->assertSame(__('common.date_of_birth_max_age', ['age' => 100]), $this->errorFor('1920-01-01'));
        $this->assertSame('1925-11-10', DateOfBirth::earliestAllowedDate()->toDateString());
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertNull($this->errorFor(null));
        $this->assertNull($this->errorFor(''));

        // Called directly too: the validator skips empty values before a rule
        // runs, so this is the rule's own guard.
        foreach ([null, ''] as $empty) {
            (new DateOfBirth)->validate('dob', $empty, fn () => $this->fail('An empty value must pass.'));
        }
    }

    #[Test]
    public function the_us_form_also_takes_month_first_dates(): void
    {
        $rule = DateOfBirth::acceptingUsFormat();

        $this->assertNull($this->errorFor('04-12-1985', $rule));
        $this->assertNull($this->errorFor('4/12/1985', $rule));
        $this->assertNull($this->errorFor('1985-04-12', $rule));
        // Day-first is not a date in this form - 13 is not a month.
        $this->assertSame(__('common.date_of_birth_invalid'), $this->errorFor('13/01/1985', $rule));
        $this->assertSame(__('common.date_of_birth_minimum_age', ['age' => 18]), $this->errorFor('11-10-2008', $rule));
        $this->assertNull($this->errorFor('11-09-2008', $rule));
    }

    #[Test]
    public function someone_born_on_the_29th_of_february_turns_eighteen_on_the_1st_of_march_in_a_common_year(): void
    {
        Carbon::setTestNow('2026-02-28 12:00:00');
        $this->assertNotNull($this->errorFor('2008-02-29'));

        Carbon::setTestNow('2026-03-01 12:00:00');
        $this->assertNull($this->errorFor('2008-02-29'));
    }

    #[Test]
    public function on_the_29th_of_february_the_cutoff_does_not_roll_into_march(): void
    {
        // 18 years before 2028-02-29 is 2010-02-28: someone born on 1 March
        // 2010 is still 17 that day.
        Carbon::setTestNow('2028-02-29 12:00:00');

        $this->assertNull($this->errorFor('2010-02-28'));
        $this->assertNotNull($this->errorFor('2010-03-01'));
    }

    #[Test]
    public function the_minimum_age_comes_from_config(): void
    {
        config(['app.minimum_age' => 21]);

        $this->assertSame(21, DateOfBirth::minimumAge());
        $this->assertSame('You must be at least 21 years old to continue.', $this->errorFor('2008-11-09'));
        $this->assertNull($this->errorFor('2005-11-09'));
    }

    #[Test]
    public function the_latest_allowed_date_is_exposed_for_the_date_pickers(): void
    {
        $this->assertSame('2008-11-09', DateOfBirth::latestAllowedDate()->toDateString());
    }

    #[Test]
    public function the_cutoff_moves_with_the_current_date(): void
    {
        Carbon::setTestNow('2027-03-15 12:00:00');

        $this->assertSame('2009-03-15', DateOfBirth::latestAllowedDate()->toDateString());
        $this->assertNull($this->errorFor('2009-03-15'));
        $this->assertSame(__('common.date_of_birth_minimum_age', ['age' => 18]), $this->errorFor('2009-03-16'));
    }
}
