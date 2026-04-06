<?php

namespace Database\Seeders;

use App\Models\Monitoring\SystemMetric;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SystemMetricSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data for a clean chart
        SystemMetric::truncate();

        $now = Carbon::now();
        $metrics = [];

        // Generate metrics for the last 24 hours at 30-min intervals
        for ($i = 48; $i >= 0; $i--) {
            $metrics[] = [
                'cpu_usage' => rand(15, 65) + (sin($i / 5) * 10),
                'ram_usage' => 60 + rand(5, 15),
                'disk_usage' => 42 + ($i * 0.05), // gradual increase
                'users_count' => rand(100, 200),
                'created_at' => $now->copy()->subMinutes($i * 30),
            ];
        }

        SystemMetric::insert($metrics);
    }
}
