<?php

namespace Tests\Integration\Console;

use App\Console\Commands\RestoreDatabaseCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Restoring a database backup: the backup is pulled from the disk the backups
 * are written to, unzipped, and its SQL dump piped into mysql. The mysql call
 * is faked and the backup disks are fakes; nothing real is read or restored.
 */
#[CoversClass(RestoreDatabaseCommand::class)]
class RestoreDatabaseCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
        foreach (['app', 'framework/views', 'framework/cache/data', 'logs'] as $dir) {
            File::ensureDirectoryExists("{$this->storage}/{$dir}");
        }
        $this->app->useStoragePath($this->storage);

        Storage::fake('local');
        Storage::fake('s3');
        Process::fake();
        config([
            'backup.backup.name' => 'fch',
            'backup.backup.destination.disks' => ['local'],
            'database.default' => 'mysql',
            'database.connections.mysql' => ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'username' => 'restorer', 'password' => 's3cr3t', 'database' => 'fch'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /** A backup zip holding one SQL dump, as spatie/laravel-backup writes it. */
    private function backup(string $disk, string $path, string $dump = 'mysql-fch.sql', ?int $modified = null): void
    {
        $zipPath = "{$this->storage}/" . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString("db-dumps/{$dump}", 'CREATE TABLE t (id INT);');
        $zip->close();

        Storage::disk($disk)->put($path, File::get($zipPath));
        File::delete($zipPath);
        if ($modified) {
            touch(Storage::disk($disk)->path($path), $modified);
        }
    }

    private function ranMysqlWith(string $dump): bool
    {
        $ran = false;
        Process::assertRan(function (PendingProcess $process) use ($dump, &$ran) {
            return $ran = str_contains($process->command, 'mysql') && str_contains($process->command, $dump);
        });

        return $ran;
    }

    #[Test]
    public function the_named_backup_is_unzipped_and_its_dump_piped_into_mysql(): void
    {
        $this->backup('local', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip', '--force' => true])->assertSuccessful();

        $this->assertTrue($this->ranMysqlWith('mysql-fch.sql'));
        $this->assertDirectoryDoesNotExist("{$this->storage}/app/backup-restore-temp", 'the extracted dump is cleaned up');
    }

    #[Test]
    public function latest_picks_the_newest_backup(): void
    {
        $this->backup('local', 'fch/older.zip', 'mysql-older.sql', strtotime('2026-06-01'));
        $this->backup('local', 'fch/newer.zip', 'mysql-newer.sql', strtotime('2026-06-14'));

        $this->artisan('backup:restore', ['--latest' => true, '--force' => true])->assertSuccessful();

        $this->assertTrue($this->ranMysqlWith('mysql-newer.sql'));
    }

    /**
     * Defect: the default disk came from env('BACKUP_DISK') / env('APP_ENV')
     * read inside the command. Under config:cache env() is null for values
     * that live only in .env, so production fell back to the local disk and
     * found none of the S3 backups.
     */
    #[Test]
    public function by_default_it_reads_the_disk_the_backups_are_written_to(): void
    {
        config(['backup.backup.destination.disks' => ['s3']]);
        $this->backup('s3', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip', '--force' => true])->assertSuccessful();

        $this->assertTrue($this->ranMysqlWith('mysql-fch.sql'));
    }

    /**
     * Defect: the password was written into the mysql command line, where any
     * user on the server can read it from the process list.
     */
    #[Test]
    public function the_database_password_is_not_on_the_command_line(): void
    {
        $this->backup('local', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip', '--force' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => !str_contains($process->command, 's3cr3t')
            && ($process->environment['MYSQL_PWD'] ?? null) === 's3cr3t');
    }

    /**
     * Defect: every failure returned with exit code 0, so a script or deploy
     * step running the restore could not tell it had failed.
     */
    #[Test]
    public function each_failure_exits_with_a_failure_code(): void
    {
        $this->artisan('backup:restore')->assertFailed();
        $this->artisan('backup:restore', ['--file' => 'fch/missing.zip'])->assertFailed();

        Storage::disk('local')->put('fch/broken.zip', 'not a zip');
        $this->artisan('backup:restore', ['--file' => 'fch/broken.zip', '--force' => true])->assertFailed();

        Process::fake(['*' => Process::result(errorOutput: 'ERROR 1045 (28000): Access denied', exitCode: 1)]);
        $this->backup('local', 'fch/2026-06-15.zip');
        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip', '--force' => true])->assertFailed();
    }

    #[Test]
    public function a_backup_without_a_sql_dump_is_refused(): void
    {
        $zipPath = "{$this->storage}/files-only.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('storage/app/readme.txt', 'files only');
        $zip->close();
        Storage::disk('local')->put('fch/files-only.zip', File::get($zipPath));

        $this->artisan('backup:restore', ['--file' => 'fch/files-only.zip', '--force' => true])->assertFailed();

        Process::assertNothingRan();
    }

    /**
     * Defect (D13/O44): a restore overwrote the configured database at once -
     * no prompt, and nothing stopping it in production.
     */
    #[Test]
    public function a_restore_asks_first_and_does_nothing_when_declined(): void
    {
        $this->backup('local', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip'])
            ->expectsConfirmation('This will overwrite the database "' . config('database.connections.' . config('database.default') . '.database') . '" with fch/2026-06-15.zip. Continue?', 'no')
            ->assertFailed();

        Process::assertNothingRan();
    }

    #[Test]
    public function a_confirmed_restore_goes_ahead(): void
    {
        $this->backup('local', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip'])
            ->expectsConfirmation('This will overwrite the database "' . config('database.connections.' . config('database.default') . '.database') . '" with fch/2026-06-15.zip. Continue?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($this->ranMysqlWith('mysql-fch.sql'));
    }

    #[Test]
    public function production_refuses_without_force_and_does_not_even_ask(): void
    {
        $this->app['env'] = 'production';
        $this->backup('local', 'fch/2026-06-15.zip');

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip'])->assertFailed();
        Process::assertNothingRan();

        $this->artisan('backup:restore', ['--file' => 'fch/2026-06-15.zip', '--force' => true])->assertSuccessful();
        $this->assertTrue($this->ranMysqlWith('mysql-fch.sql'));
    }
}
