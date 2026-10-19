<?php

namespace Tests\Unit\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccess;
use App\Services\Borrower\CapitalAccess\StepTimeLock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E-016: roadmap steps unlock on a schedule (a "Day N" delay after the play
 * starts, and a time-of-day window), but only the portal enforced it - a direct
 * POST to /step/{id}/submit was accepted for a step the roadmap showed locked.
 * StepTimeLock mirrors the portal's isTimeActive() so the API can refuse it.
 */
class StepTimeLockTest extends TestCase
{
    private const WINDOWS = ['Morning' => [6, 11], 'Mid Day' => [11, 14], 'Anytime' => [9, 23.98]];

    private function reason(array $step, bool $isFirst, ?string $firstSubmittedAt, string $now): ?string
    {
        return StepTimeLock::reason(
            $step['access_time'] ?? null,
            $step['access_day'] ?? null,
            $step['access_day_time'] ?? null,
            $isFirst,
            $firstSubmittedAt ? CarbonImmutable::parse($firstSubmittedAt) : null,
            CarbonImmutable::parse($now),
            self::WINDOWS
        );
    }

    #[Test]
    public function a_day_step_is_locked_until_its_day(): void
    {
        $step = ['access_time' => CapitalAccess::ACCESS_TIME_DAY, 'access_day' => 2];

        $this->assertNotNull($this->reason($step, false, '2026-10-12 10:00:00', '2026-10-13 15:00:00')); // day 1
        $this->assertNull($this->reason($step, false, '2026-10-12 10:00:00', '2026-10-14 00:30:00'));    // day 2
    }

    #[Test]
    public function a_day_step_counts_calendar_days_not_hours(): void
    {
        $step = ['access_time' => CapitalAccess::ACCESS_TIME_DAY, 'access_day' => 1];

        // 23:50 -> 00:10 next day is one calendar day.
        $this->assertNull($this->reason($step, false, '2026-10-12 23:50:00', '2026-10-13 00:10:00'));
    }

    #[Test]
    public function a_windowed_step_is_locked_before_its_window(): void
    {
        $step = ['access_time' => 'Mid Day'];

        $this->assertNotNull($this->reason($step, false, null, '2026-10-13 09:00:00'));
        $this->assertNull($this->reason($step, false, null, '2026-10-13 12:00:00'));
    }

    #[Test]
    public function after_the_window_only_the_first_step_is_locked(): void
    {
        $step = ['access_time' => 'Mid Day'];

        $this->assertNotNull($this->reason($step, true, null, '2026-10-13 15:00:00'));
        $this->assertNull($this->reason($step, false, null, '2026-10-13 15:00:00'));
    }

    #[Test]
    public function a_day_step_uses_its_day_time_window(): void
    {
        $step = ['access_time' => CapitalAccess::ACCESS_TIME_DAY, 'access_day' => 1, 'access_day_time' => 'Morning'];

        $this->assertNotNull($this->reason($step, false, '2026-10-12 08:00:00', '2026-10-13 05:00:00'));
        $this->assertNull($this->reason($step, false, '2026-10-12 08:00:00', '2026-10-13 07:00:00'));
    }

    #[Test]
    public function anytime_is_never_locked(): void
    {
        $this->assertNull($this->reason(['access_time' => CapitalAccess::ACCESS_TIME_ANYTIME], true, null, '2026-10-13 03:00:00'));
    }
}
