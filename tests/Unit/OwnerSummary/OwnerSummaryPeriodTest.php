<?php

namespace Tests\Unit\OwnerSummary;

use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The windows the two newer periods cover, and the ones they are compared with. */
#[CoversClass(OwnerSummaryPeriod::class)]
class OwnerSummaryPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function this_week_runs_sunday_to_saturday_against_the_week_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC')); // a Wednesday

        $period = OwnerSummaryPeriod::make(OwnerSummaryPeriod::THIS_WEEK, 'UTC')->toArray();

        $this->assertSame('this_week', $period['key']);
        $this->assertSame('2026-11-08', $period['from']);
        $this->assertSame('2026-11-14', $period['to']);
        $this->assertSame('2026-11-01', $period['previous_from']);
        $this->assertSame('2026-11-07', $period['previous_to']);
    }

    #[Test]
    public function the_week_is_the_viewers_week_not_the_utc_one(): void
    {
        // Still Saturday the 7th in New York, already Sunday the 8th in UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-08 02:00:00', 'UTC'));

        $period = OwnerSummaryPeriod::make(OwnerSummaryPeriod::THIS_WEEK, 'America/New_York');

        $this->assertSame('2026-11-01', $period->from->toDateString());
        $this->assertSame('2026-11-07', $period->to->toDateString());
    }

    #[Test]
    public function last_week_is_the_week_before_this_one_against_the_week_before_that(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00', 'America/New_York')); // a Sunday

        $period = OwnerSummaryPeriod::make(OwnerSummaryPeriod::LAST_WEEK, 'America/New_York')->toArray();

        $this->assertSame('last_week', $period['key']);
        $this->assertSame('2026-11-01', $period['from']);
        $this->assertSame('2026-11-07', $period['to']);
        $this->assertSame('2026-10-25', $period['previous_from']);
        $this->assertSame('2026-10-31', $period['previous_to']);
    }

    #[Test]
    public function a_custom_range_is_compared_with_the_same_number_of_days_just_before(): void
    {
        $period = OwnerSummaryPeriod::custom('2026-10-01', '2026-10-31', 'UTC')->toArray();

        $this->assertSame('custom', $period['key']);
        $this->assertSame('2026-10-01', $period['from']);
        $this->assertSame('2026-10-31', $period['to']);
        $this->assertSame('2026-08-31', $period['previous_from']);
        $this->assertSame('2026-09-30', $period['previous_to']);
    }

    #[Test]
    public function a_single_day_is_compared_with_the_day_before(): void
    {
        $period = OwnerSummaryPeriod::custom('2026-10-05', '2026-10-05', 'UTC')->toArray();

        $this->assertSame('2026-10-04', $period['previous_from']);
        $this->assertSame('2026-10-04', $period['previous_to']);
    }
}
