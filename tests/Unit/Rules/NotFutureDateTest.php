<?php

namespace Tests\Unit\Rules;

use App\Rules\NotFutureDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-007: a business start date in the future was accepted. The date is read
 * the way normalizeDate() stores it (YYYY-MM-DD, MM-DD-YYYY or MM/DD/YYYY).
 */
class NotFutureDateTest extends TestCase
{
    private function passes(mixed $value): bool
    {
        return Validator::make(['d' => $value], ['d' => [new NotFutureDate()]])->passes();
    }

    #[Test]
    public function today_and_earlier_pass_tomorrow_and_later_fail_in_every_format(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $this->assertTrue($this->passes('2026-11-02'));
        $this->assertTrue($this->passes('01-01-2020'));
        $this->assertTrue($this->passes('01/01/2020'));
        $this->assertFalse($this->passes('2026-11-03'));
        $this->assertFalse($this->passes('2027-01-01'));
        $this->assertFalse($this->passes('01-01-2027'));
        $this->assertFalse($this->passes('01/01/2027'));

        Carbon::setTestNow();
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertTrue($this->passes(null));
        $this->assertTrue($this->passes(''));
    }
}
