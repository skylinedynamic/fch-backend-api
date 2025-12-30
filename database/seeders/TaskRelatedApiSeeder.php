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
                'name' => 'Business Address Verification',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'base_url' => 'https://api.microbilt.com/AddressNameVerification',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'MicroBilt API for business verification and credit reporting',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Phone Verification By Name',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'base_url' => 'https://api.microbilt.com/PhoneVerification',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'MicroBilt API for business verification and credit reporting',
                'created_at' => now(),
                'updated_at' => now(),
            ],
             [
                'name' => 'Phone Verification By Address',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'base_url' => 'https://api.microbilt.com/PhoneNameVerification',
                'client_id' => 'your_client_id_here',
                'client_secret' => 'your_client_secret_here',
                'additional_info' => 'MicroBilt API for business verification and credit reporting',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        DB::table('task_related_apis')->insert($apis);
    }
}
