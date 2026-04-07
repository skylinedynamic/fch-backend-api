<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Monitoring\Host;
use App\Models\Monitoring\Check;
use App\Models\Monitoring\SystemMetric;
use Carbon\Carbon;

class SystemMonitoringSeeder extends Seeder
{
    public function run()
    {
        // 1. Clear existing data
        SystemMetric::truncate();
        Check::query()->delete();
        Host::query()->delete();

        // 2. Create Fleet Nodes
        $pod1 = Host::create([
            'name' => 'FCH Production Node A',
            'ip' => '102.164.21.18',
            'ssh_user' => 'fch_admin',
            'port' => 22,
        ]);

        $pod2 = Host::create([
            'name' => 'FCH Global API Staging',
            'ip' => '157.240.22.35',
            'ssh_user' => 'staging_deploy',
            'port' => 2222,
        ]);

        $pod3 = Host::create([
            'name' => 'Legacy Backup Vault',
            'ip' => '172.16.0.45',
            'ssh_user' => 'vault_mgr',
            'port' => 22,
        ]);

        // 3. Add Checks to Nodes
        $checks = ['mysql', 'disk_space', 'ssh', 'memcached', 'elasticsearch'];

        foreach ([$pod1, $pod2, $pod3] as $index => $host) {
            foreach ($checks as $cIndex => $type) {
                // Randomize status for visual variety
                $status = ($index == 2 && $cIndex > 2) ? 'warning' : 'success';
                if ($index == 2 && $cIndex == 4)
                    $status = 'failed';

                Check::create([
                    'host_id' => $host->id,
                    'type' => $type,
                    'status' => $status,
                    'enabled' => true,
                    'last_ran_at' => Carbon::now()->subMinutes(rand(1, 60)),
                    'last_run_message' => $status === 'success' ? 'Protocol verified: All metrics within operational threshold.' : 'Warning: latency detected in resource response.',
                ]);
            }
        }

        // 4. Resource History (30 Days of Hourly Snapshots = 720 points)
        $now = Carbon::now();
        for ($i = 0; $i < 720; $i++) {
            SystemMetric::create([
                'cpu_usage' => rand(15, 85),
                'ram_usage' => rand(40, 95),
                'disk_usage' => rand(30, 45),
                'users_count' => rand(500, 1500),
                'created_at' => $now->copy()->subHours(720 - $i),
            ]);
        }
    }
}
