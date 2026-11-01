<?php

namespace Tests\Unit\Helpers;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Time in business, counted to today or to a given date. The signed
 * application PDF counts it to the day it was signed, so it does not grow
 * every time the PDF is downloaded again (BUG-094).
 */
class CalculateTimeInBusinessTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_counts_to_today_by_default(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->assertSame('5 Years 19 Days', calculateTimeInBusiness('2021-10-02'));
    }

    #[Test]
    public function it_counts_to_the_date_it_is_given(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->assertSame('5 Years 17 Days', calculateTimeInBusiness('2021-10-02', '2026-10-19 08:30:00'));
    }

    #[Test]
    public function a_start_after_the_given_date_is_zero_days(): void
    {
        $this->assertSame('0 Days', calculateTimeInBusiness('2026-10-20', '2026-10-19'));
    }

    #[Test]
    public function no_start_date_is_not_applicable(): void
    {
        $this->assertSame('N/A', calculateTimeInBusiness(null, '2026-10-19'));
    }
}
