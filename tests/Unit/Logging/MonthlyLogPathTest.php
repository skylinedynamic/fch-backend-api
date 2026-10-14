<?php

namespace Tests\Unit\Logging;

use App\Logging\MonthlyDailyLogger;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Daily logs must land in a folder named for the CURRENT month.
 *
 * The path used to be built in config/logging.php with `date('F')`, which is
 * evaluated when the config file is read. `artisan config:cache` reads it once
 * and writes the result in as a literal, so on a cached deployment the month was
 * frozen at deploy time: October's entries kept landing in `September/` until
 * someone rebuilt the cache, with nothing to indicate it.
 *
 * These pin both halves of the fix - the month is resolved at runtime, and the
 * config carries no month for a cache to freeze.
 */
#[CoversClass(MonthlyDailyLogger::class)]
class MonthlyLogPathTest extends TestCase
{
    #[Test]
    public function the_daily_channel_resolves_its_path_at_runtime(): void
    {
        // A literal path in config is exactly what config:cache freezes.
        $this->assertSame('custom', config('logging.channels.daily.driver'));
        $this->assertSame(MonthlyDailyLogger::class, config('logging.channels.daily.via'));
        $this->assertArrayNotHasKey(
            'path',
            config('logging.channels.daily'),
            'a path in config would be baked in by config:cache and freeze the month'
        );
    }

    #[Test]
    public function the_resolved_path_names_the_current_month(): void
    {
        $logger = (new MonthlyDailyLogger())(config('logging.channels.daily'));

        // Log::build() returns Laravel's wrapper around the Monolog instance.
        $this->assertInstanceOf(LaravelLogger::class, $logger);

        $monolog = $logger->getLogger();
        $this->assertInstanceOf(Logger::class, $monolog);

        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(
            RotatingFileHandler::class,
            $handler,
            'rotation and retention should still come from Laravel\'s daily driver'
        );

        $path = str_replace('\\', '/', $handler->getUrl());

        // Year and month (findings O42): month alone mixed this June with last.
        $this->assertStringContainsString('/logs/' . date('Y') . '/' . date('F') . '/', $path);
        $this->assertStringContainsString(date('Y-m-d'), $path);
    }

    #[Test]
    public function no_month_name_is_stored_in_the_logging_config(): void
    {
        // Whatever the shape of the channel, nothing under logging may carry a
        // month - that is the value config:cache would turn into a literal.
        $months = array_map(
            fn (int $m) => date('F', mktime(0, 0, 0, $m, 1)),
            range(1, 12)
        );

        $encoded = json_encode(config('logging'));

        foreach ($months as $month) {
            $this->assertStringNotContainsString(
                '/' . $month . '/',
                str_replace('\\', '/', (string) $encoded),
                "the logging config contains the month {$month}; config:cache would freeze it"
            );
        }
    }
}
