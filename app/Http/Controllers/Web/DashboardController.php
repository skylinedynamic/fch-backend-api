<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Models\Monitoring\SystemMetric;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;

class DashboardController extends Controller
{
    public function index()
    {
        // Cache system stats for 60s to avoid spawning shell commands on every page load.
        $stats = Cache::remember('dashboard.system_stats', 60, fn() => $this->getSystemStats());

        // User count rarely changes quickly; cache briefly.
        $userCount = Cache::remember('dashboard.user_count', 60, fn() => \App\Models\User\User::count());

        // Backup destination inspection can be slow (especially on S3). Cache for 5 minutes.
        $latestBackupDate = Cache::remember('dashboard.latest_backup_date', 300, function () {
            $monitorConfig = config('backup.monitor_backups');
            $latestBackup = collect($monitorConfig)->map(function ($config) {
                $config['healthChecks'] = $config['health_checks'] ?? [];
                $status = BackupDestinationStatusFactory::createForSingleMonitor($config)->first();
                return $status ? $status->backupDestination()->newestBackup() : null;
            })->filter()->sortByDesc(fn($b) => $b->date())->first();

            return $latestBackup ? $latestBackup->date() : null;
        });

        // Health checks run on a schedule; just read the latest stored results.
        $healthResults = Cache::remember('dashboard.health_results', 60, fn() => app(ResultStore::class)->latestResults());
        $isHealthy = $healthResults ? $healthResults->storedCheckResults->every(fn($r) => $r->status === 'ok') : true;

        return view('system_monitoring.dashboard.overview', [
            'stats' => $stats,
            'userCount' => $userCount,
            'latestBackupDate' => $latestBackupDate,
            'isHealthy' => $isHealthy,
            'healthResults' => $healthResults,
            'env' => ucfirst(app()->environment()),
            'phpVersion' => phpversion()
        ]);
    }

    /**
     * Show database backups.
     */
    public function backups(Request $request)
    {
        $cacheKey = 'dashboard.backups_list';

        // Allow operators to force a fresh read when debugging remote disk issues.
        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        // Backup destination inspection can be expensive on remote disks. Cache the
        // rendered list for 5 minutes; clear it automatically after restore/delete.
        $backups = Cache::remember($cacheKey, 300, function () {
            $monitorConfig = config('backup.monitor_backups');

            $statuses = collect($monitorConfig)->flatMap(function ($config) {
                // The factory expects 'healthChecks' (camelCase) but config uses 'health_checks' (snake_case)
                $config['healthChecks'] = $config['health_checks'] ?? [];

                try {
                    return BackupDestinationStatusFactory::createForSingleMonitor($config);
                } catch (\Throwable $e) {
                    Log::error('Backup monitor factory failed.', [
                        'config' => $config,
                        'error' => $e->getMessage(),
                    ]);

                    return collect();
                }
            });

            return $statuses->map(function (BackupDestinationStatus $status) {
                $destination = $status->backupDestination();

                try {
                    $files = $destination->backups();

                    return [
                        'name' => $destination->backupName(),
                        'disk' => $destination->diskName(),
                        'reachable' => $destination->isReachable(),
                        'connection_error' => $destination->connectionError?->getMessage(),
                        'healthy' => $status->isHealthy(),
                        'files' => $files->map(function ($backup) {
                            return [
                                'path' => $backup->path(),
                                'date' => $backup->date(),
                                'size' => $backup->sizeInBytes(),
                            ];
                        }),
                        'storage_used' => $this->formatBytes($destination->usedStorage()),
                        'newest_backup' => $destination->newestBackup() ? $destination->newestBackup()->date() : null,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Failed to read backup destination.', [
                        'disk' => $destination->diskName(),
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'name' => $destination->backupName(),
                        'disk' => $destination->diskName(),
                        'reachable' => false,
                        'connection_error' => $e->getMessage(),
                        'healthy' => false,
                        'files' => collect(),
                        'storage_used' => '0 B',
                        'newest_backup' => null,
                    ];
                }
            });
        });

        return view('system_monitoring.dashboard.backups', compact('backups'));
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Download a specific backup snapshot.
     */
    public function downloadBackup(Request $request)
    {
        $diskName = $request->input('disk');
        $path = $request->input('path');

        if (!in_array($diskName, config('backup.backup.destination.disks'))) {
            abort(403, 'Unauthorized disk access.');
        }

        $disk = Storage::disk($diskName);

        if (!$disk->exists($path)) {
            abort(404, 'Backup file not found.');
        }

        return $disk->download($path);
    }

    /**
     * Restore database from a specific snapshot.
     */
    public function restoreBackup(Request $request)
    {
        $diskName = $request->input('disk');
        $path = $request->input('path');

        if (!in_array($diskName, config('backup.backup.destination.disks'))) {
            return back()->with('error', 'Critical Alert: Target storage disk is out of bounds.');
        }

        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            return back()->with('error', 'System Error: Backup identity mismatch or file missing.');
        }

        try {
            // Ensure temp directory exists
            $tempPath = storage_path('app/restore-temp');
            if (!file_exists($tempPath))
                mkdir($tempPath, 0755, true);

            // 1. Fetch snapshot to local storage
            $zipPath = $tempPath . '/snapshot.zip';
            file_put_contents($zipPath, $disk->get($path));

            // 2. Extract database payload
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) === TRUE) {
                $sqlFile = null;
                // Spatie backups usually nest SQL in db-dumps/mysql-db_name.sql
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (str_ends_with($filename, '.sql')) {
                        $sqlFile = $filename;
                        break;
                    }
                }

                if (!$sqlFile) {
                    $zip->close();
                    unlink($zipPath);
                    return back()->with('error', 'Security Breach: No valid SQL payload found in the archive.');
                }

                $zip->extractTo($tempPath, $sqlFile);
                $zip->close();

                $sqlFullPath = $tempPath . '/' . $sqlFile;

                // 3. Perform atomic restoration
                $connection = config('database.default');
                $dbConfig = config("database.connections.{$connection}");

                if ($connection === 'mysql') {
                    // Optimized shell import
                    $command = sprintf(
                        'mysql --user=%s --password=%s --host=%s %s < %s',
                        escapeshellarg($dbConfig['username']),
                        escapeshellarg($dbConfig['password'] ?? ''),
                        escapeshellarg($dbConfig['host']),
                        escapeshellarg($dbConfig['database']),
                        escapeshellarg($sqlFullPath)
                    );

                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        shell_exec($command);
                    }
                    else {
                        exec($command);
                    }
                }
                else {
                    // Binary import fallback
                    DB::unprepared(file_get_contents($sqlFullPath));
                }

                // 4. Systematic Cleanup
                unlink($zipPath);
                unlink($sqlFullPath);

                // Remove recursive spatie folders if they exist
                $segments = explode('/', str_replace('\\', '/', $sqlFile));
                if (count($segments) > 1) {
                    @rmdir($tempPath . '/' . $segments[0]); // e.g. db-dumps
                }

                // Invalidate cached backup data after a restore.
                Cache::forget('dashboard.backups_list');
                Cache::forget('dashboard.latest_backup_date');

                return back()->with('success', 'System Restoration Complete. Database synchronized to: ' . basename($path));
            }
            else {
                return back()->with('error', 'Extraction Failed: The snapshot archive appears to be corrupted.');
            }
        }
        catch (\Exception $e) {
            return back()->with('error', 'Engine Failure: ' . $e->getMessage());
        }
    }

    /**
     * Permanent removal of a backup snapshot.
     */
    public function deleteBackup(Request $request)
    {
        $diskName = $request->input('disk');
        $path = $request->input('path');

        if (!in_array($diskName, config('backup.backup.destination.disks'))) {
            return back()->with('error', 'Security Violation: Access to target disk restricted.');
        }

        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            return back()->with('error', 'Operation Blocked: The snapshot has already been relocated or deleted.');
        }

        try {
            $disk->delete($path);

            // Invalidate cached backup data after deletion.
            Cache::forget('dashboard.backups_list');
            Cache::forget('dashboard.latest_backup_date');

            return back()->with('success', 'Security Cleanup Complete. Snapshot permanently purged from: ' . strtoupper($diskName));
        }
        catch (\Exception $e) {
            return back()->with('error', 'Execution Interrupted: ' . $e->getMessage());
        }
    }
    /**
     * Show historical system performance charts.
     */
    public function charts()
    {
        // Pull the last 24 hours of snapshots instead of a fixed row count. This is
        // more predictable and keeps the chart light even if the scheduler runs frequently.
        $metrics = Cache::remember('dashboard.chart_metrics', 60, function () {
            return SystemMetric::query()
                ->where('created_at', '>=', now()->subDay())
                ->orderBy('created_at', 'asc')
                ->select(['id', 'cpu_usage', 'ram_usage', 'disk_usage', 'created_at'])
                ->get();
        });

        return view('system_monitoring.dashboard.charts', compact('metrics'));
    }

    public function scheduler()
    {
        // Inspect the Laravel scheduler to show a human-readable list of all
        // scheduled tasks, their cron expressions, and next run times.
        // Console routes (where schedules are defined) are not loaded on web
        // requests, so we need to load them manually to populate the Schedule.
        $consoleRoutes = base_path('routes/console.php');
        if (file_exists($consoleRoutes)) {
            require $consoleRoutes;
        }

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $events = collect($schedule->events())->map(function ($event) {
            $command = $event->command ?? $event->description ?? 'Closure';

            // Strip the PHP binary prefix and quotes that Laravel internally prepends.
            if (is_string($command) && str_contains($command, 'artisan')) {
                $command = preg_replace('#^".*?php(?:\.exe)?"\s+"?artisan"?\s+#i', '', $command);
            }

            return [
                'command' => $command,
                'description' => $event->description,
                'expression' => $event->expression,
                'next_run' => $event->nextRunDate(),
            ];
        });

        return view('system_monitoring.dashboard.scheduler', compact('events'));
    }

    public function logs()
    {
        return view('system_monitoring.dashboard.iframe', [
            'title' => 'Log Viewer',
            'subtitle' => 'System Monitoring',
            'url' => '/log-viewer',
        ]);
    }

    public function docsAdmin()
    {
        return view('system_monitoring.dashboard.iframe', [
            'title' => 'Admin API Docs',
            'subtitle' => 'API Reference (Admin)',
            'url' => '/docs/admin',
        ]);
    }

    public function docsBorrower()
    {
        return view('system_monitoring.dashboard.iframe', [
            'title' => 'Borrower API Docs',
            'subtitle' => 'API Reference (Borrower)',
            'url' => '/docs/borrower',
        ]);
    }

    /**
     * Show real-time system health metrics.
     */
    public function health()
    {
        // Cache system stats so shell commands are not run on every page refresh.
        $stats = Cache::remember('dashboard.system_stats', 60, fn() => $this->getSystemStats());

        // Do NOT run health:check synchronously here — it blocks the request and can
        // include external pings. The scheduler already runs health:check periodically.
        // Just read the latest stored results.
        $healthResults = Cache::remember('dashboard.health_results', 60, fn() => app(ResultStore::class)->latestResults());

        return view('system_monitoring.dashboard.health', compact('stats', 'healthResults'));
    }

    private function getSystemStats()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // 1. CPU Usage
        $cpu = 0;
        if ($isWindows) {
            // Average LoadPercentage across all processors (multi-socket/multi-core safe).
            $cpu = (int)shell_exec('powershell -Command "$v=(Get-CimInstance Win32_Processor | Measure-Object LoadPercentage -Average).Average; if($v -eq $null){0} else {[math]::Round($v)}"');
        }
        else {
            // sys_getloadavg returns the 1/5/15 minute load averages (number of runnable processes).
            // Convert to a percentage by dividing by the number of CPU cores.
            $load = sys_getloadavg();
            $cores = $this->getCpuCoreCount();
            $cpu = ($load && $cores > 0) ? (int) round(($load[0] / $cores) * 100) : 0;
        }

        // 2. RAM Usage
        $totalRamGB = 0;
        $usedRamGB = 0;
        $ramUsagePercent = 0;

        if ($isWindows) {
            $totalRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).TotalVisibleMemorySize"');
            $freeRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory"');
            $usedRamKB = $totalRamKB - $freeRamKB;
            $totalRamGB = round($totalRamKB / 1024 / 1024, 1);
            $usedRamGB = round($usedRamKB / 1024 / 1024, 1);
            $ramUsagePercent = $totalRamKB > 0 ? ($usedRamKB / $totalRamKB) * 100 : 0;
        }
        else {
            $free = shell_exec('free -m');
            if ($free) {
                $free = (string)trim($free);
                $free_arr = explode("\n", $free);
                $mem = explode(" ", preg_replace("/\s+/", " ", $free_arr[1]));
                $totalRamMB = $mem[1];
                $usedRamMB = $mem[2];
                $totalRamGB = round($totalRamMB / 1024, 1);
                $usedRamGB = round($usedRamMB / 1024, 1);
                $ramUsagePercent = ($usedRamMB / $totalRamMB) * 100;
            }
        }

        // 3. Disk Usage
        $diskPath = $isWindows ? "C:" : "/";
        $totalDisk = disk_total_space($diskPath);
        $freeDisk = disk_free_space($diskPath);
        $usedDisk = $totalDisk - $freeDisk;
        $diskUsagePercent = ($usedDisk / $totalDisk) * 100;

        // 4. Services Status
        $services = [
            'Nginx / Apache' => $this->checkPort('localhost', 80) || $this->checkPort('localhost', 443),
            'Database' => $this->checkPort('localhost', 3306),
            'PHP Engine' => $this->checkPort('127.0.0.1', 9000) || $this->checkPHPProcess(),
        ];

        // 5. Uptime
        $uptime = "Unknown";
        if ($isWindows) {
            $bootTimeRaw = shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).LastBootUpTime"');
            $uptime = $this->parseUptime($bootTimeRaw, true);
        }
        else {
            $uptimeRaw = shell_exec('uptime -p');
            $uptime = $uptimeRaw ? trim(str_replace('up ', '', $uptimeRaw)) : "Unknown";
        }

        return [
            'cpu' => $cpu,
            'ram' => round($ramUsagePercent, 1),
            'ram_total' => $totalRamGB,
            'ram_used' => $usedRamGB,
            'disk' => round($diskUsagePercent, 1),
            'disk_total' => round($totalDisk / 1024 / 1024 / 1024, 1),
            'disk_used' => round($usedDisk / 1024 / 1024 / 1024, 1),
            'services' => $services,
            'uptime' => $uptime
        ];
    }

    private function checkPort($host, $port)
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function checkPHPProcess()
    {
        return function_exists('phpversion');
    }

    private function getCpuCoreCount(): int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cores = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_Processor).NumberOfLogicalProcessors | Measure-Object -Sum | Select-Object -ExpandProperty Sum"');
            return $cores > 0 ? $cores : 1;
        }

        $nproc = (int)shell_exec('nproc 2>/dev/null');
        if ($nproc > 0) {
            return $nproc;
        }

        $cpuinfo = shell_exec('cat /proc/cpuinfo | grep -c ^processor 2>/dev/null');
        if ($cpuinfo) {
            $cores = (int)trim($cpuinfo);
            return $cores > 0 ? $cores : 1;
        }

        return 1;
    }

    private function parseUptime($raw, $isWindows = false)
    {
        if (!$raw)
            return "Unknown";
        try {
            if ($isWindows) {
                // LastBootUpTime looks like "Monday, April 6, 2026 5:48:32 AM" or ISO
                $boot = new \DateTime($raw);
                $now = new \DateTime();
                $diff = $now->diff($boot);
                return "$diff->d days, $diff->h hours, $diff->i minutes";
            }
            return $raw;
        }
        catch (\Exception $e) {
            return "Unknown";
        }
    }
}
