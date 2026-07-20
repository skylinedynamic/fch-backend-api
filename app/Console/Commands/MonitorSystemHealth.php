<?php

namespace App\Console\Commands;

use App\Models\Monitoring\SystemMetric;
use App\Models\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class MonitorSystemHealth extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'app:monitor-health';

    /**
     * The console command description.
     */
    protected $description = 'Snap system health metrics and send alerts if thresholds are exceeded';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $metrics = $this->getSystemMetrics();
        $userCount = User::count();

        // 1. Save snapshot to DB
        SystemMetric::create([
            'cpu_usage' => $metrics['cpu_usage_ratio'] * 100,
            'ram_usage' => $metrics['memory_usage_ratio'] * 100,
            'disk_usage' => $metrics['disk_usage_ratio'] * 100,
            'users_count' => $userCount,
        ]);

        // Invalidate cached chart data so the charts dashboard reflects the new snapshot.
        Cache::forget('dashboard.chart_metrics');

        $this->info('System health snapshot saved.');

        // 2. Check for alerts
        $this->checkAlerts($metrics);
    }

    private function getSystemMetrics(): array
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $cpu = 0;
        if ($isWindows) {
            $cpu = (int)shell_exec('powershell -Command "$v=(Get-CimInstance Win32_Processor | Measure-Object LoadPercentage -Average).Average; if($v -eq $null){0} else {[math]::Round($v)}"');
        } else {
            $load = sys_getloadavg();
            $cores = max(1, (int)shell_exec('nproc 2>/dev/null') ?: (int)shell_exec('cat /proc/cpuinfo | grep -c ^processor 2>/dev/null') ?: 1);
            $cpu = ($load && $cores > 0) ? round(($load[0] / $cores) * 100, 2) : 0;
        }

        $ramUsagePercent = 0;
        if ($isWindows) {
            $totalRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).TotalVisibleMemorySize"');
            $freeRamKB = (int)shell_exec('powershell -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory"');
            $ramUsagePercent = $totalRamKB > 0 ? (($totalRamKB - $freeRamKB) / $totalRamKB) * 100 : 0;
        } else {
            $free = shell_exec('free -m');
            if ($free) {
                $free_arr = explode("\n", trim($free));
                $mem = explode(" ", preg_replace("/\s+/", " ", $free_arr[1]));
                $ramUsagePercent = ($mem[2] / $mem[1]) * 100;
            }
        }

        $diskPath = $isWindows ? "C:" : "/";
        $totalDisk = disk_total_space($diskPath);
        $freeDisk = disk_free_space($diskPath);
        $diskUsagePercent = $totalDisk > 0 ? (($totalDisk - $freeDisk) / $totalDisk) * 100 : 0;

        return [
            'cpu_usage_ratio' => $cpu / 100,
            'memory_usage_ratio' => $ramUsagePercent / 100,
            'disk_usage_ratio' => $diskUsagePercent / 100,
        ];
    }

    private function checkAlerts($metrics)
    {
        $alerts = [];
        if ($metrics['cpu_usage_ratio'] > 0.85)
            $alerts[] = 'CRITICAL: CPU Usage is above 85%';
        if ($metrics['memory_usage_ratio'] > 0.90)
            $alerts[] = 'CRITICAL: RAM Usage is above 90%';
        if ($metrics['disk_usage_ratio'] > 0.95)
            $alerts[] = 'CRITICAL: Disk Space is above 95%';

        if (!empty($alerts)) {
        // $this->sendAlertEmail($alerts);
        }
    }

    private function sendAlertEmail($alerts)
    {
        try {
            // In theory you'd send to your admin email
            $adminEmail = config('mail.from.address', 'admin@fch.com');

            // Sending raw email for immediate notification
            Mail::raw(implode("\n", $alerts), function ($message) use ($adminEmail) {
                $message->to($adminEmail)
                    ->subject('⚠️ System Health Alert - FCH API');
            });

            $this->warn('Health alerts sent to ' . $adminEmail);
        }
        catch (\Exception $e) {
            $this->error('Failed to send email alert: ' . $e->getMessage());
        }
    }
}
