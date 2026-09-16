<?php

namespace Tests\Unit\Monitoring;

use App\Console\Commands\MonitorSystemHealth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * When the health monitor decides something is wrong.
 *
 * These thresholds went unwatched for a long time: the command computed them on
 * every run and then discarded the result, because the only line that sent the
 * alert was commented out. Now that they reach an inbox, getting them wrong has
 * a cost in both directions - noise, or a full disk nobody hears about.
 *
 * alertsFor() is pure, so no database, no mail and no console are involved.
 */
#[CoversClass(MonitorSystemHealth::class)]
class HealthAlertThresholdsTest extends TestCase
{
    /** @return array<int, array{metric: string, usage: float, threshold: float}> */
    private function alertsFor(array $metrics): array
    {
        $method = new ReflectionMethod(MonitorSystemHealth::class, 'alertsFor');
        $method->setAccessible(true);

        return $method->invoke(app(MonitorSystemHealth::class), $metrics);
    }

    /** @return array<int, string> */
    private function breached(array $metrics): array
    {
        return array_column($this->alertsFor($metrics), 'metric');
    }

    #[Test]
    public function a_healthy_machine_raises_nothing(): void
    {
        $this->assertSame([], $this->alertsFor([
            'cpu_usage_ratio' => 0.12,
            'memory_usage_ratio' => 0.40,
            'disk_usage_ratio' => 0.55,
        ]));
    }

    #[Test]
    public function each_resource_is_judged_against_its_own_limit(): void
    {
        // CPU 85%, RAM 90%, Disk 95% - deliberately different, so a single
        // shared limit would show up here.
        $this->assertSame(['CPU Usage'], $this->breached([
            'cpu_usage_ratio' => 0.86,
            'memory_usage_ratio' => 0.86,
            'disk_usage_ratio' => 0.86,
        ]));

        $this->assertSame(['CPU Usage', 'RAM Usage'], $this->breached([
            'cpu_usage_ratio' => 0.91,
            'memory_usage_ratio' => 0.91,
            'disk_usage_ratio' => 0.91,
        ]));

        $this->assertSame(['CPU Usage', 'RAM Usage', 'Disk Space'], $this->breached([
            'cpu_usage_ratio' => 0.96,
            'memory_usage_ratio' => 0.96,
            'disk_usage_ratio' => 0.96,
        ]));
    }

    #[Test]
    public function sitting_exactly_on_the_limit_is_not_a_breach(): void
    {
        // At the limit is at the limit, not over it - otherwise a disk parked at
        // exactly 95% would alert on every run, forever.
        $this->assertSame([], $this->alertsFor([
            'cpu_usage_ratio' => 0.85,
            'memory_usage_ratio' => 0.90,
            'disk_usage_ratio' => 0.95,
        ]));
    }

    #[Test]
    public function the_alert_carries_the_reading_and_the_limit_it_broke(): void
    {
        // The mail prints both, so an operator can tell "just over" from
        // "wildly over" without opening a dashboard.
        $alerts = $this->alertsFor([
            'cpu_usage_ratio' => 0.10,
            'memory_usage_ratio' => 0.10,
            'disk_usage_ratio' => 0.97,
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame('Disk Space', $alerts[0]['metric']);
        $this->assertEqualsWithDelta(0.97, $alerts[0]['usage'], 0.0001);
        $this->assertEqualsWithDelta(0.95, $alerts[0]['threshold'], 0.0001);
    }

    #[Test]
    public function a_missing_reading_is_treated_as_idle_rather_than_alerting(): void
    {
        // getSystemMetrics() returns 0 when a shell probe fails (an unavailable
        // `free`, a blocked powershell call). That is an absent reading, not a
        // saturated machine, and must not page anyone.
        $this->assertSame([], $this->alertsFor([]));
        $this->assertSame([], $this->alertsFor(['cpu_usage_ratio' => 0.0]));
    }
}
