<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base test case.
 *
 * Boots the real application from bootstrap/app.php, so tests exercise the same
 * container, config and service bindings the app runs with.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSqliteCompatibilityFunctions();
    }

    /**
     * Teach SQLite the MySQL functions the app's own queries use.
     *
     * The suite runs on an in-memory SQLite database so no database server has
     * to exist for tests to run. PHP still bundles SQLite 3.40 though, and
     * CONCAT_WS only arrived in 3.44, so Borrower::search() would fail on the
     * driver rather than on the app. Registering it here keeps the shim in the
     * test harness instead of bending production queries to suit the test DB.
     */
    private function registerSqliteCompatibilityFunctions(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();

        if (!method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        // CONCAT_WS(separator, ...values): join with the separator, skipping
        // nulls - so an absent middle name leaves no double space behind.
        $pdo->sqliteCreateFunction('CONCAT_WS', function ($separator, ...$values) {
            if ($separator === null) {
                return null;
            }

            $present = array_filter($values, static fn($value) => $value !== null);

            return implode((string) $separator, array_map('strval', $present));
        });
    }
}
