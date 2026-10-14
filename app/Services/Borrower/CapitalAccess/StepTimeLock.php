<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccess;
use Carbon\CarbonInterface;

/**
 * When a capital-access roadmap step is open, by the clock.
 *
 * Mirrors the borrower portal's isTimeActive() (PlayRoadmap.tsx) so the API
 * refuses what the roadmap shows as locked (E2E-016):
 *  - a "Day N" step opens N calendar days after the play started;
 *  - a step with a time window (Morning, Mid Day, ...) is locked before the
 *    window opens, and - for the first step only - after it closes.
 *
 * Pure: the caller supplies "now" in the borrower's timezone and the windows as
 * [label => [start, end]] hours, so this is testable without a database.
 */
class StepTimeLock
{
    /**
     * @param array<string, array{0: float|int, 1: float|int}> $windows label => [start hour, end hour]
     * @return string|null why the step is locked, or null when it is open
     */
    public static function reason(
        ?string $accessTime,
        ?int $accessDay,
        ?string $accessDayTime,
        bool $isFirstStep,
        ?CarbonInterface $playStartedAt,
        CarbonInterface $now,
        array $windows
    ): ?string {
        if ($accessTime === CapitalAccess::ACCESS_TIME_DAY && $accessDay && $playStartedAt) {
            $days = (int) $playStartedAt->setTimezone($now->getTimezone())->startOfDay()->diffInDays($now->startOfDay());
            if ($days < $accessDay) {
                return "This step unlocks on Day {$accessDay}.";
            }
        }

        $label = null;
        if ($accessTime === CapitalAccess::ACCESS_TIME_DAY) {
            if ($accessDayTime && $accessDayTime !== CapitalAccess::ACCESS_TIME_ANYTIME) {
                $label = $accessDayTime;
            }
        } elseif ($accessTime && $accessTime !== CapitalAccess::ACCESS_TIME_ANYTIME) {
            $label = $accessTime;
        }

        if ($label === null) {
            return null;
        }

        $window = null;
        foreach ($windows as $name => $range) {
            if (strcasecmp($name, $label) === 0) {
                $window = $range;
                break;
            }
        }
        if ($window === null) {
            return null;
        }

        [$start, $end] = [(float) $window[0], (float) $window[1]];
        $hour = $now->hour + $now->minute / 60;

        if ($hour < $start || ($isFirstStep && $hour > $end)) {
            return "This step is only open during {$label}.";
        }

        return null;
    }
}
