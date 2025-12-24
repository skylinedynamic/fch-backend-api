<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskRelatedApiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data to prevent duplicates
        DB::table('task_related_apis')->truncate();

        $apis = [
            [
                'name' => 'MicroBilt API',
                'base_url' => 'https://api.microbilt.com',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'MicroBilt API for business verification and credit reporting',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'D&B API',
                'base_url' => 'https://api.dnb.com',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'Dun & Bradstreet API for DUNS number verification',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Plaid API',
                'base_url' => 'https://production.plaid.com',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'Plaid API for bank account verification',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('task_related_apis')->insert($apis);
    }
}
