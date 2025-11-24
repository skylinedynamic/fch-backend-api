<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Common department names to choose from
        $allDepartments = [
            'Operations',
            'Finance',
            'IT',
            'HR',
            'Sales',
            'Customer Support',
            'Marketing',
            'Research & Development',
            'Quality Assurance',
            'Legal',
            'Administration',
            'Procurement',
            'Logistics',
            'Business Development',
            'Product Management',
        ];

        $bulk = [];
        
        // Get all partners
        $partners = DB::table('partners')->pluck('id');

        foreach ($partners as $partnerId) {
            // Randomly select 5-7 departments for each partner
            $numberOfDepartments = rand(5, 7);
            
            // Shuffle and take the required number of departments
            $shuffledDepartments = $allDepartments;
            shuffle($shuffledDepartments);
            $selectedDepartments = array_slice($shuffledDepartments, 0, $numberOfDepartments);

            // Create department records
            foreach ($selectedDepartments as $departmentName) {
                $bulk[] = [
                    'partner_id' => $partnerId,
                    'name' => $departmentName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insert all departments at once
        DB::table('departments')->insert($bulk);
    }
}
