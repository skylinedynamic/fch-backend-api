<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Monitoring\SystemMetric;
use Carbon\Carbon;

class SystemMonitoringSeeder extends Seeder
{
    public function run()
    {
        // 1. Clear existing data
        SystemMetric::truncate();

        // 2. Resource History (30 Days of Hourly Snapshots = 720 points)
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
