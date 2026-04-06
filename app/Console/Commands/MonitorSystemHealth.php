<?php

namespace App\Console\Commands;

use App\Models\Monitoring\SystemMetric;
use App\Models\User\User;
use App\Services\Monitoring\PrometheusStats;
use Illuminate\Console\Command;
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
    public function handle(PrometheusStats $stats)
    {
        $metrics = $stats->getMetrics();
        $userCount = User::count();

        // 1. Save snapshot to DB
        SystemMetric::create([
            'cpu_usage' => $metrics['cpu_usage_ratio'] * 100,
            'ram_usage' => $metrics['memory_usage_ratio'] * 100,
            'disk_usage' => $metrics['disk_usage_ratio'] * 100,
            'users_count' => $userCount,
        ]);

        $this->info('System health snapshot saved.');

        // 2. Check for alerts
        $this->checkAlerts($metrics);
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
