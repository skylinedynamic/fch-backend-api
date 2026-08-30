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
                'name' => 'EIN Verification Api',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'additional_info' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business Address Verification Api',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'additional_info' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business Phone Verification Api',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'additional_info' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business Identity Verification Api',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'additional_info' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Active Legal Entity Verification Api',
                'provider' => 'MicroBilt',
                'tier_id' => 1,
                'additional_info' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        DB::table('task_related_apis')->insert($apis);
    }
}
