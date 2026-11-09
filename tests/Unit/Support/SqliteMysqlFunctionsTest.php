<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The MySQL functions the app's own queries use, taught to the suite's SQLite.
 *
 * Defect (O21): CONCAT, DATE_FORMAT and CONVERT_TZ are MySQL-only, so queries
 * using them (verification centre, download history, invoices, payments,
 * subscriptions) failed on SQLite and stayed untested. The base TestCase now
 * registers them with MySQL's semantics, as it already did for CONCAT_WS.
 */
class SqliteMysqlFunctionsTest extends TestCase
{
    private function select(string $expression, array $bindings = []): mixed
    {
        return DB::selectOne("SELECT {$expression} AS v", $bindings)->v;
    }

    #[Test]
    public function concat_joins_its_arguments_and_any_null_makes_it_null(): void
    {
        $this->assertSame('Dana Whitfield', $this->select("CONCAT('Dana', ' ', 'Whitfield')"));
        $this->assertSame('INV-42', $this->select("CONCAT('INV-', 42)"));
        $this->assertNull($this->select("CONCAT('Dana', NULL)"));
    }

    #[Test]
    public function date_format_follows_mysqls_specifiers(): void
    {
        $at = '2026-06-05 14:07:09';

        $this->assertSame('Jun 05, 2026', $this->select("DATE_FORMAT(?, '%b %d, %Y')", [$at]));
        $this->assertSame('June 05, 2026', $this->select("DATE_FORMAT(?, '%M %d, %Y')", [$at]));
        $this->assertSame('2026-06-05 02:07 PM', $this->select("DATE_FORMAT(?, '%Y-%m-%d %h:%i %p')", [$at]));
        $this->assertSame('June 05, 2026 02:07 PM', $this->select("DATE_FORMAT(?, '%M %d, %Y %h:%i %p')", [$at]));
        $this->assertSame('14:07:09 100%', $this->select("DATE_FORMAT(?, '%T 100%%')", [$at]));
        $this->assertNull($this->select('DATE_FORMAT(NULL, ?)', ['%Y']));
    }

    #[Test]
    public function convert_tz_moves_a_time_between_offsets_and_named_zones(): void
    {
        $this->assertSame('2026-06-05 08:00:00', $this->select("CONVERT_TZ('2026-06-05 12:00:00', '+00:00', '-04:00')"));
        $this->assertSame('2026-06-05 08:00:00', $this->select("CONVERT_TZ('2026-06-05 12:00:00', 'UTC', 'America/New_York')"));
        // Nested as a timezone-aware date search uses them: 02:00 UTC is the day before in New York.
        $this->assertSame('Jun 04, 2026', $this->select("DATE_FORMAT(CONVERT_TZ('2026-06-05 02:00:00', '+00:00', '-04:00'), '%b %d, %Y')"));
        $this->assertNull($this->select("CONVERT_TZ(NULL, '+00:00', '-04:00')"));
    }
}
