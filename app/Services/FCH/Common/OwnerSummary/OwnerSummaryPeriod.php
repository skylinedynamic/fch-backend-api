<?php

namespace App\Services\FCH\Common\OwnerSummary;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The window the owner summary covers and the one it is compared with, in the
 * viewer's timezone. Calendar periods are compared with the calendar period
 * before (November with October, a Sunday-to-Saturday week with the week
 * before); "last 30 days" with the 30 days before it; a custom range with the
 * same number of days just before it.
 *
 * Bounds are dates (inclusive); between() gives the matching UTC instants for
 * timestamp columns, so a payment at 23:30 New York time on the 31st is in
 * October for a New York viewer.
 */
final class OwnerSummaryPeriod
{
    public const THIS_WEEK = 'this_week';
    public const LAST_WEEK = 'last_week';
    public const THIS_MONTH = 'this_month';
    public const LAST_MONTH = 'last_month';
    public const THIS_QUARTER = 'this_quarter';
    public const THIS_YEAR = 'this_year';
    public const LAST_30_DAYS = 'last_30_days';
    public const CUSTOM = 'custom';

    public const KEYS = [
        self::THIS_WEEK,
        self::LAST_WEEK,
        self::THIS_MONTH,
        self::LAST_MONTH,
        self::THIS_QUARTER,
        self::THIS_YEAR,
        self::LAST_30_DAYS,
        self::CUSTOM,
    ];

    public const DEFAULT = self::THIS_MONTH;

    private const WEEK_DAYS = 7;

    private function __construct(
        public readonly string $key,
        public readonly string $timezone,
        public readonly CarbonImmutable $today,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $previousFrom,
        public readonly CarbonImmutable $previousTo,
    ) {
    }

    public static function make(string $key, string $timezone): self
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        [$from, $to, $previousFrom, $previousTo] = match ($key) {
            self::THIS_WEEK => [
                $today->startOfWeek(CarbonInterface::SUNDAY), $today->endOfWeek(CarbonInterface::SATURDAY)->startOfDay(),
                $today->startOfWeek(CarbonInterface::SUNDAY)->subDays(self::WEEK_DAYS), $today->startOfWeek(CarbonInterface::SUNDAY)->subDay(),
            ],
            self::LAST_WEEK => [
                $today->startOfWeek(CarbonInterface::SUNDAY)->subDays(self::WEEK_DAYS), $today->startOfWeek(CarbonInterface::SUNDAY)->subDay(),
                $today->startOfWeek(CarbonInterface::SUNDAY)->subDays(2 * self::WEEK_DAYS), $today->startOfWeek(CarbonInterface::SUNDAY)->subDays(self::WEEK_DAYS + 1),
            ],
            self::THIS_MONTH => [
                $today->startOfMonth(), $today->endOfMonth()->startOfDay(),
                $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            ],
            self::LAST_MONTH => [
                $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                $today->subMonthsNoOverflow(2)->startOfMonth(), $today->subMonthsNoOverflow(2)->endOfMonth()->startOfDay(),
            ],
            self::THIS_QUARTER => [
                $today->startOfQuarter(), $today->endOfQuarter()->startOfDay(),
                $today->startOfQuarter()->subMonthsNoOverflow(3), $today->startOfQuarter()->subDay(),
            ],
            self::THIS_YEAR => [
                $today->startOfYear(), $today->endOfYear()->startOfDay(),
                $today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay(),
            ],
            self::LAST_30_DAYS => [
                $today->subDays(29), $today,
                $today->subDays(59), $today->subDays(30),
            ],
            default => throw new InvalidArgumentException("Unknown owner summary period [{$key}]."),
        };

        return new self($key, $timezone, $today, $from, $to, $previousFrom, $previousTo);
    }

    /** A chosen range of local Y-m-d dates, already validated (see FetchOwnerSummary). */
    public static function custom(string $from, string $to, string $timezone): self
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);

        return new self(
            self::CUSTOM,
            $timezone,
            CarbonImmutable::now($timezone)->startOfDay(),
            $start,
            $end,
            $start->subDays(self::daysInRange($from, $to)),
            $start->subDay(),
        );
    }

    /** A range of Y-m-d dates for a person to read: "1 – 7 Nov 2026". */
    public static function rangeLabel(string $from, string $to): string
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        return match (true) {
            $from === $to => $end->format('j M Y'),
            $start->isSameMonth($end) => $start->format('j') . ' – ' . $end->format('j M Y'),
            $start->isSameYear($end) => $start->format('j M') . ' – ' . $end->format('j M Y'),
            default => $start->format('j M Y') . ' – ' . $end->format('j M Y'),
        };
    }

    /** How many days a range of Y-m-d dates covers, counting both ends. */
    public static function daysInRange(string $from, string $to): int
    {
        return (int) CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC')
            ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC')) + 1;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC instants [start, end) of the period. */
    public function between(): array
    {
        return self::utcRange($this->from, $this->to);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function previousBetween(): array
    {
        return self::utcRange($this->previousFrom, $this->previousTo);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC instants [start, end) of the given local dates. */
    public static function utcRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->startOfDay()->utc(), $to->addDay()->startOfDay()->utc()];
    }

    /**
     * The months the charts show: the six ending with the period's last month.
     *
     * @return list<CarbonImmutable> first day of each month, oldest first
     */
    public function chartMonths(int $count = 6): array
    {
        $last = $this->to->startOfMonth();

        return array_map(fn (int $i) => $last->subMonthsNoOverflow($i), range($count - 1, 0));
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'timezone' => $this->timezone,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'previous_from' => $this->previousFrom->toDateString(),
            'previous_to' => $this->previousTo->toDateString(),
        ];
    }
}
