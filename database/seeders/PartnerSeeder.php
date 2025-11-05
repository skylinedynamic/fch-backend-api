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
        // Resolve employee IDs dynamically to avoid dependency on static IDs
        $johnEmployeeId = DB::table('employees')->where('user_id', 2)->value('id');
        $sarahEmployeeId = DB::table('employees')->where('user_id', 3)->value('id');
        $adminEmployeeId = DB::table('employees')->where('user_id', 1)->value('id');

        DB::table('partners')->insert([
                [
                'user_id' => 1, // Admin User is also a partner
                'business_id' => 1, // Admin's business
                'employee_id' => $adminEmployeeId, // Admin as employee
                'status' => 'active',
                'assign_to' => 1, // Self-assigned or managed by admin
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 2, // John Partner
                'business_id' => 1, // Tech Solutions Inc
                'employee_id' => $johnEmployeeId, // John Partner as employee
                'status' => 'active',
                'assign_to' => 1, // Assigned to Admin
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 3, // Sarah Wilson
                'business_id' => 2, // Green Energy Solutions LLC
                'employee_id' => $sarahEmployeeId, // Sarah Wilson as employee
                'status' => 'active',
                'assign_to' => 1, // Assigned to Admin
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}