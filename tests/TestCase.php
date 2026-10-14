<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Base test case.
 *
 * Boots the real application from bootstrap/app.php, so tests exercise the same
 * container, config and service bindings the app runs with.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** This test's own storage folder, removed when the test ends. */
    private ?string $isolatedRoot = null;

    /** The real log manager, whose open files are closed before that folder goes. */
    private ?LogManager $logManager = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateStorage();
        $this->registerSqliteCompatibilityFunctions();
    }

    protected function tearDown(): void
    {
        $root = $this->isolatedRoot;
        $this->isolatedRoot = null;

        // Windows will not remove a folder while a log file in it is still
        // open, so the loggers let go of their files first. The manager kept
        // from setUp is used because a test may have swapped Log for a mock.
        foreach ((array) $this->logManager?->getChannels() as $channel) {
            $logger = method_exists($channel, 'getLogger') ? $channel->getLogger() : $channel;
            if (method_exists($logger, 'close')) {
                $logger->close();
            }
        }
        $this->logManager = null;

        parent::tearDown();

        if ($root !== null) {
            (new Filesystem())->deleteDirectory($root);
        }
    }

    /**
     * Run the test against a throwaway storage folder, never the real one.
     *
     * The developer's storage/app/public holds real uploads under the same low
     * ids the tests create, and tests that wrote or cleaned up there have
     * deleted real files. So every test gets its own folder: storage_path(), the
     * disks and the log files all point into it, with the Passport keys copied
     * across so tokens still sign. A test that isolates further (its own
     * useStoragePath(), Storage::fake()) simply overrides this.
     *
     * The disk roots and log paths were built from the real storage path when
     * the config loaded, so they are re-pointed too - useStoragePath() alone
     * would leave Storage::disk('public') writing into the real folder.
     */
    private function isolateStorage(): void
    {
        $files = new Filesystem();
        $real = rtrim(storage_path(), '\\/');
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fch-test-' . bin2hex(random_bytes(6));
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';

        foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $dir) {
            $files->ensureDirectoryExists($storage . DIRECTORY_SEPARATOR . $dir);
        }
        foreach (glob($real . DIRECTORY_SEPARATOR . 'oauth-*.key') ?: [] as $key) {
            $files->copy($key, $storage . DIRECTORY_SEPARATOR . basename($key));
        }

        $this->isolatedRoot = $root;
        $this->app->useStoragePath($storage);

        $normalise = static fn (string $path): string => str_replace('\\', '/', $path);
        $repoint = static function ($path) use ($real, $storage, $normalise) {
            return is_string($path) && str_starts_with($normalise($path), $normalise($real))
                ? $storage . substr($path, strlen($real))
                : $path;
        };

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            if (isset($disk['root'])) {
                config(["filesystems.disks.{$name}.root" => $repoint($disk['root'])]);
            }
        }
        foreach ((array) config('logging.channels') as $name => $channel) {
            if (isset($channel['path'])) {
                config(["logging.channels.{$name}.path" => $repoint($channel['path'])]);
            }
        }

        // Anything already resolved against the old paths is rebuilt on next use.
        Storage::forgetDisk(array_keys((array) config('filesystems.disks')));
        foreach (array_keys((array) config('logging.channels')) as $name) {
            Log::forgetChannel($name);
        }

        $manager = $this->app->make('log');
        $this->logManager = $manager instanceof LogManager ? $manager : null;
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

        // CONCAT(...values): MySQL returns NULL if any argument is NULL.
        $pdo->sqliteCreateFunction('CONCAT', function (...$values) {
            foreach ($values as $value) {
                if ($value === null) {
                    return null;
                }
            }

            return implode('', array_map('strval', $values));
        });

        // DATE_FORMAT(date, format) with MySQL's % specifiers.
        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($date, $format) {
            if ($date === null || $format === null) {
                return null;
            }

            $map = [
                'Y' => 'Y', 'y' => 'y', 'm' => 'm', 'c' => 'n', 'd' => 'd', 'e' => 'j',
                'H' => 'H', 'k' => 'G', 'h' => 'h', 'I' => 'h', 'l' => 'g', 'i' => 'i',
                's' => 's', 'S' => 's', 'p' => 'A', 'b' => 'M', 'M' => 'F', 'a' => 'D',
                'W' => 'l', 'T' => 'H:i:s', 'r' => 'h:i:s A', 'j' => 'z',
            ];
            $moment = new \DateTimeImmutable((string) $date);

            return preg_replace_callback('/%(.)/', function ($m) use ($map, $moment) {
                if ($m[1] === '%') {
                    return '%';
                }
                if ($m[1] === 'j') {
                    return str_pad((string) ((int) $moment->format('z') + 1), 3, '0', STR_PAD_LEFT);
                }

                return isset($map[$m[1]]) ? $moment->format($map[$m[1]]) : $m[1];
            }, (string) $format);
        });

        // CONVERT_TZ(datetime, from, to) with offsets ('+00:00') or zone names.
        $pdo->sqliteCreateFunction('CONVERT_TZ', function ($date, $from, $to) {
            if ($date === null || $from === null || $to === null) {
                return null;
            }

            try {
                return (new \DateTimeImmutable((string) $date, new \DateTimeZone((string) $from)))
                    ->setTimezone(new \DateTimeZone((string) $to))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return null; // as MySQL does for a zone it does not know
            }
        });
    }
}
