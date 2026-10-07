<?php

namespace App\Console\Commands;

use App\Services\FCH\Common\Notification\Notifier;
use App\Mail\SystemHealthAlert;
use App\Models\Monitoring\SystemMetric;
use App\Models\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * The reading at which each resource is considered in trouble.
     *
     * Unchanged from the thresholds this command has always used; they are
     * named here so the alert can report the limit it breached rather than
     * repeating the number in a sentence.
     */
    private const THRESHOLDS = [
        'CPU Usage' => ['key' => 'cpu_usage_ratio', 'limit' => 0.85],
        'RAM Usage' => ['key' => 'memory_usage_ratio', 'limit' => 0.90],
        'Disk Space' => ['key' => 'disk_usage_ratio', 'limit' => 0.95],
    ];

    /**
     * Which resources have breached, and by how much.
     *
     * Deliberately pure - no mail, no console, no database - so the thresholds
     * can be tested directly. They are the part most likely to break silently:
     * a wrong comparison here means either an inbox full of noise or, worse, a
     * full disk nobody hears about.
     *
     * @return array<int, array{metric: string, usage: float, threshold: float}>
     */
    private function alertsFor(array $metrics): array
    {
        $alerts = [];

        foreach (self::THRESHOLDS as $label => $threshold) {
            $usage = (float) ($metrics[$threshold['key']] ?? 0);

            // Strictly greater than: a resource sitting exactly on its limit is
            // at the limit, not over it.
            if ($usage > $threshold['limit']) {
                $alerts[] = [
                    'metric' => $label,
                    'usage' => $usage,
                    'threshold' => $threshold['limit'],
                ];
            }
        }

        return $alerts;
    }

    private function checkAlerts(array $metrics): void
    {
        $alerts = $this->alertsFor($metrics);

        if (empty($alerts)) {
            return;
        }

        foreach ($alerts as $alert) {
            $this->warn(sprintf(
                'CRITICAL: %s is at %.1f%% (limit %.0f%%)',
                $alert['metric'],
                $alert['usage'] * 100,
                $alert['threshold'] * 100
            ));
        }

        $this->sendAlertEmail($alerts, $metrics);
    }

    /**
     * Notify the operators.
     *
     * Addressed to CRON_ERROR_NOTIFY_EMAILS, the same list the cron failure
     * alert uses, and sent as a Mailable rather than with Mail::raw(). That is
     * not a cosmetic change: raw sends bypass the MailConfig trait, and since
     * credentials moved out of config/mail.php into `email_configurations` a raw
     * send has no mail server to connect to. The Mailable pins the HOST
     * partner's configuration - this is the platform's own alert, not a
     * tenant's correspondence.
     */
    private function sendAlertEmail(array $alerts, array $metrics): void
    {
        $recipients = array_filter(array_map('trim', explode(',', (string) config('app.cron_notify_emails', ''))));

        if (empty($recipients)) {
            // Worth saying out loud: the thresholds were breached and nobody is
            // being told, which looks identical to "nothing is wrong".
            $this->error('Health thresholds breached but CRON_ERROR_NOTIFY_EMAILS is empty; no alert sent.');
            Log::warning('System health alert not sent: no recipients configured.', ['alerts' => $alerts]);

            return;
        }

        try {
            app(Notifier::class)->send('system.health_alert', mail: (new SystemHealthAlert($alerts, [
                'CPU Usage' => (float) ($metrics['cpu_usage_ratio'] ?? 0),
                'RAM Usage' => (float) ($metrics['memory_usage_ratio'] ?? 0),
                'Disk Space' => (float) ($metrics['disk_usage_ratio'] ?? 0),
            ]))->to($recipients));

            $this->warn('Health alert sent to ' . implode(', ', $recipients));
            Log::warning('System health alert sent.', [
                'recipients' => $recipients,
                'alerts' => $alerts,
            ]);
        } catch (\Throwable $e) {
            // The snapshot above is already saved; a failed alert must not take
            // the scheduled command down with it.
            $this->error('Failed to send health alert: ' . $e->getMessage());
            Log::error('Failed to send system health alert: ' . $e->getMessage(), ['alerts' => $alerts]);
        }
    }
}
