<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PartnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('partners')->insert([
            [
                'user_id' => 2, // John Partner
                'business_id' => 1, // Tech Solutions Inc
                'employee_id' => 1, // John Partner as employee
                'status' => 'active',
                'assign_to' => 1, // Assigned to Admin
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 3, // Sarah Wilson
                'business_id' => 2, // Green Energy Solutions LLC
                'employee_id' => 2, // Sarah Wilson as employee
                'status' => 'active',
                'assign_to' => 1, // Assigned to Admin
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}