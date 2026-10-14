<?php

namespace Tests\Integration\Console;

use App\Console\Commands\MonitorSystemHealth;
use App\Console\Commands\PruneMonitoringHistory;
use App\Console\Commands\PruneOldLogs;
use App\Console\Commands\SchedulerHeartbeat;
use App\HealthChecks\SchedulerRanCheck;
use App\Models\Monitoring\SystemMetric;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * The housekeeping the scheduler runs on its own: the heartbeat the health
 * dashboard watches, the five-minute health snapshot, and the pruning of old log folders and monitoring
 * snapshots. Logs are pruned in a throwaway storage folder, never the real one.
 */
#[CoversClass(SchedulerHeartbeat::class)]
#[CoversClass(SchedulerRanCheck::class)]
#[CoversClass(PruneOldLogs::class)]
#[CoversClass(PruneMonitoringHistory::class)]
#[CoversClass(MonitorSystemHealth::class)]
class SchedulerMaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
        foreach (['app/public', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $dir) {
            File::ensureDirectoryExists("{$this->storage}/{$dir}");
        }
        $this->copyPassportKeys(storage_path(), $this->storage);
        $this->app->useStoragePath($this->storage);
        Cache::forget('scheduler.last_run_at');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function logFolders(): array
    {
        $folders = array_map('basename', File::directories("{$this->storage}/logs"));
        sort($folders);

        return $folders;
    }

    private function monthFolders(string ...$months): void
    {
        foreach ($months as $month) {
            File::ensureDirectoryExists("{$this->storage}/logs/{$month}");
            File::put("{$this->storage}/logs/{$month}/laravel.log", "{$month}\n");
        }
    }

    #[Test]
    public function the_heartbeat_records_when_the_scheduler_last_ran(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');
        Http::fake();

        $this->artisan('scheduler:heartbeat')->assertSuccessful();

        $this->assertSame('2026-06-15 12:00:00', Cache::get('scheduler.last_run_at'));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_heartbeat_pings_the_monitor_url_and_survives_it_failing(): void
    {
        config(['app.schedule_heartbeat_url' => 'https://heartbeat.example.test/ping']);
        Http::fake(['heartbeat.example.test/*' => Http::response('down', 503)]);

        $this->artisan('scheduler:heartbeat')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://heartbeat.example.test/ping');
        $this->assertNotNull(Cache::get('scheduler.last_run_at'));
    }

    #[Test]
    public function the_health_check_fails_without_a_recent_heartbeat(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');
        $check = new SchedulerRanCheck();

        $this->assertSame(Status::failed(), $check->run()->status);

        Cache::forever('scheduler.last_run_at', '2026-06-15 11:59:00');
        $this->assertSame(Status::ok(), $check->run()->status);

        Cache::forever('scheduler.last_run_at', '2026-06-15 09:00:00');
        $this->assertSame(Status::failed(), $check->run()->status);
    }

    /**
     * Defect (O43): the heartbeat runs every minute but the check only failed
     * after two hours, so a stopped scheduler went unnoticed for up to two
     * hours of missed billing and reminder runs.
     */
    #[Test]
    public function a_scheduler_silent_for_a_quarter_of_an_hour_is_reported(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');
        $check = new SchedulerRanCheck();

        Cache::forever('scheduler.last_run_at', '2026-06-15 11:51:00');
        $this->assertSame(Status::ok(), $check->run()->status);

        Cache::forever('scheduler.last_run_at', '2026-06-15 11:45:00');
        $this->assertSame(Status::failed(), $check->run()->status);
    }

    #[Test]
    public function log_folders_older_than_six_months_are_removed(): void
    {
        Carbon::setTestNow('2026-06-15 03:00:00');
        $this->monthFolders('January', 'February', 'March', 'April', 'May', 'June', 'December', 'November');
        File::ensureDirectoryExists("{$this->storage}/logs/archive");

        $this->artisan('logs:prune-months')->assertSuccessful();

        $this->assertSame(['April', 'February', 'January', 'June', 'March', 'May', 'archive'], $this->logFolders());
    }

    /**
     * Defect: the months to keep were built with strtotime("-N months"), which
     * overflows past a short month - on 31 March, "-1 months" is 3 March - so
     * February was missing from the list and its logs were deleted while
     * still inside the six-month window.
     */
    #[Test]
    public function on_the_31st_the_previous_months_logs_are_kept(): void
    {
        Carbon::setTestNow('2026-03-31 03:00:00');
        $this->monthFolders('October', 'November', 'December', 'January', 'February', 'March', 'September');

        $this->artisan('logs:prune-months')->assertSuccessful();

        $this->assertSame(['December', 'February', 'January', 'March', 'November', 'October'], $this->logFolders());
    }

    /**
     * Defect (O42): log folders were named by month only, so June 2025 and
     * June 2026 shared one folder - a folder was never older than 12 months and
     * last year's logs inside the current window were never pruned. Folders
     * are now year/month, and the window is six months of real dates.
     */
    #[Test]
    public function year_and_month_folders_outside_the_six_month_window_are_removed(): void
    {
        Carbon::setTestNow('2026-06-15 03:00:00');
        foreach (['2025/June', '2025/December', '2026/January', '2026/February', '2026/March', '2026/April', '2026/May', '2026/June'] as $folder) {
            File::ensureDirectoryExists("{$this->storage}/logs/{$folder}");
            File::put("{$this->storage}/logs/{$folder}/laravel.log", "{$folder}
");
        }

        $this->artisan('logs:prune-months')->assertSuccessful();

        // Last June is gone though "June" is a month being kept; so is the now
        // empty 2025 folder.
        $this->assertSame(['2026'], $this->logFolders());
        $months = array_map('basename', File::directories("{$this->storage}/logs/2026"));
        sort($months);
        $this->assertSame(['April', 'February', 'January', 'June', 'March', 'May'], $months);
    }

    #[Test]
    public function the_app_log_is_written_into_a_year_and_month_folder(): void
    {
        Carbon::setTestNow('2026-06-15 03:00:00');

        $logger = (new \App\Logging\MonthlyDailyLogger())(['driver' => 'custom', 'level' => 'debug', 'days' => 14]);
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertStringContainsString('/logs/2026/June/', str_replace('\\', '/', $handler->getUrl()));
    }

    /**
     * The scheduler's own log was one file appended to on every run; its mtime
     * never aged past the 180 days the loose-file rule needs, so it grew for
     * ever. It now lives in the month's folder and goes with it.
     */
    #[Test]
    public function the_scheduler_log_is_written_into_the_months_folder(): void
    {
        $outputs = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => str_replace('\\', '/', (string) $event->output))
            ->filter(fn ($path) => str_contains($path, 'scheduler.log'));

        $this->assertNotEmpty($outputs);
        foreach ($outputs as $path) {
            $this->assertMatchesRegularExpression('#/logs/\d{4}/[A-Z][a-z]+/scheduler\.log$#', $path);
        }
    }

    #[Test]
    public function loose_log_files_older_than_180_days_are_removed(): void
    {
        Carbon::setTestNow('2026-06-15 03:00:00');
        $old = "{$this->storage}/logs/scheduler-old.log";
        $recent = "{$this->storage}/logs/scheduler.log";
        $other = "{$this->storage}/logs/notes.txt";
        foreach ([$old, $recent, $other] as $file) {
            File::put($file, 'x');
            touch($file, Carbon::parse('2025-11-01')->getTimestamp());
        }
        touch($recent, Carbon::parse('2026-06-01')->getTimestamp());

        $this->artisan('logs:prune-months')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other, 'only .log files are pruned');
    }

    #[Test]
    public function monitoring_snapshots_older_than_a_month_are_removed(): void
    {
        Carbon::setTestNow('2026-06-15 03:30:00');
        foreach (['2026-05-14 03:00:00', '2026-04-01 00:00:00', '2026-05-16 00:00:00', '2026-06-15 03:00:00'] as $at) {
            (new SystemMetric())->forceFill(['cpu_usage' => 10, 'ram_usage' => 20, 'disk_usage' => 30, 'created_at' => $at])->saveQuietly();
        }

        $this->artisan('monitoring:prune')->assertSuccessful();

        $this->assertSame(['2026-05-16 00:00:00', '2026-06-15 03:00:00'], SystemMetric::orderBy('created_at')->pluck('created_at')->map(fn ($at) => (string) $at)->all());
    }

    #[Test]
    public function the_health_monitor_saves_a_snapshot_and_refreshes_the_charts(): void
    {
        Mail::fake();
        config(['app.cron_notify_emails' => '']);
        Cache::forever('dashboard.chart_metrics', ['stale']);
        User::forceCreate(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'x']);

        $this->artisan('app:monitor-health')->assertSuccessful();

        $snapshot = SystemMetric::sole();
        $this->assertSame(1, $snapshot->users_count);
        foreach (['cpu_usage', 'ram_usage', 'disk_usage'] as $reading) {
            $this->assertGreaterThanOrEqual(0, $snapshot->{$reading});
            $this->assertLessThanOrEqual(100, $snapshot->{$reading});
        }
        $this->assertFalse(Cache::has('dashboard.chart_metrics'));
        Mail::assertNothingOutgoing();
    }
}
