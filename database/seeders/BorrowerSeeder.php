<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BorrowerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('borrowers')->insert([
            [
                'user_id' => 4, // Michael Johnson
                'partner_id' => 1, // John Partner
                'business_id' => 1, // Tech Solutions Inc
                'employee_id' => 3, // David Thompson
                'assign_to' => 2, // Assigned to John Partner
                'status' => 'active',
                'notes' => 'Excellent credit history, looking for business expansion loan.',
                'new_column' => 'Sample data for new column',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 5, // Emily Davis
                'partner_id' => 2, // Sarah Wilson
                'business_id' => 2, // Green Energy Solutions LLC
                'employee_id' => 4, // Lisa Martinez
                'assign_to' => 3, // Assigned to Sarah Wilson
                'status' => 'pending',
                'notes' => 'New business owner seeking startup funding for renewable energy project.',
                'new_column' => 'Additional borrower information',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 6, // Robert Brown
                'partner_id' => 1, // John Partner
                'business_id' => 3, // Urban Restaurant Group
                'employee_id' => 5, // James Rodriguez
                'assign_to' => 2, // Assigned to John Partner
                'status' => 'active',
                'notes' => 'Restaurant owner looking to expand to new locations.',
                'new_column' => 'Restaurant expansion details',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}