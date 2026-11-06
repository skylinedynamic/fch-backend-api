<?php

namespace Tests\Unit\Rules;

use App\Rules\AdultDateOfBirth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-005 (QA round 2): a client could be saved with a date of birth that made
 * them 17 (01 Jan 2009) or 106 (01 Jan 1920). A borrower signs funding
 * agreements, so they must be an adult, and an age past any human lifespan is a
 * typo. The rule takes the admin's MM-DD-YYYY and the portals' YYYY-MM-DD.
 */
#[CoversClass(AdultDateOfBirth::class)]
class AdultDateOfBirthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function errorFor(mixed $value): ?string
    {
        $validator = Validator::make(['date_of_birth' => $value], ['date_of_birth' => [new AdultDateOfBirth()]]);

        return $validator->errors()->first('date_of_birth') ?: null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedDates(): iterable
    {
        yield '18 today (YYYY-MM-DD)' => ['2008-10-21'];
        yield '18 today (MM-DD-YYYY)' => ['10-21-2008'];
        yield '40 (MM/DD/YYYY)' => ['03/15/1986'];
        yield '120 today' => ['1906-10-21'];
        yield '120, a day short of 121' => ['1905-10-22'];
    }

    #[Test]
    #[DataProvider('acceptedDates')]
    public function an_age_from_18_to_120_is_accepted(string $date): void
    {
        $this->assertNull($this->errorFor($date));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function minors(): iterable
    {
        yield 'QA: 01 Jan 2009 (17)' => ['01-01-2009'];
        yield '18 tomorrow' => ['2008-10-22'];
        yield 'born today' => ['2026-10-21'];
        yield 'in the future' => ['2030-01-01'];
    }

    #[Test]
    #[DataProvider('minors')]
    public function under_18_is_refused(string $date): void
    {
        $this->assertSame(__('common.date_of_birth_min_age', ['age' => 18]), $this->errorFor($date));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pastLifespan(): iterable
    {
        yield '121 today' => ['1905-10-21'];
        yield 'born 1800' => ['01-01-1800'];
    }

    #[Test]
    #[DataProvider('pastLifespan')]
    public function over_120_is_refused(string $date): void
    {
        $this->assertSame(__('common.date_of_birth_max_age', ['age' => 120]), $this->errorFor($date));
    }

    #[Test]
    public function qa_106_year_old_is_accepted_as_within_the_limit(): void
    {
        // 01 Jan 1920 is 106 - unusual, but under the 120 ceiling agreed for BUG-005.
        $this->assertNull($this->errorFor('01-01-1920'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notDates(): iterable
    {
        yield 'text' => ['yesterday-ish'];
        yield 'day first' => ['31-12-1990'];
        yield 'impossible day' => ['02-30-1990'];
        yield 'an array' => [['1990-01-01']];
    }

    #[Test]
    #[DataProvider('notDates')]
    public function a_value_that_is_not_a_date_is_refused(mixed $value): void
    {
        $this->assertSame(__('common.date_of_birth_invalid'), $this->errorFor($value));
    }

    #[Test]
    public function the_bounds_come_from_config(): void
    {
        config(['borrower.date_of_birth.min_age' => 21, 'borrower.date_of_birth.max_age' => 100]);

        $this->assertSame(__('common.date_of_birth_min_age', ['age' => 21]), $this->errorFor('2006-01-01'));
        $this->assertSame(__('common.date_of_birth_max_age', ['age' => 100]), $this->errorFor('1920-01-01'));
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertNull($this->errorFor(''));
        $this->assertNull($this->errorFor(null));
    }
}
