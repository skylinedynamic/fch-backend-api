<?php

namespace Database\Seeders;

use App\Models\Company\Company;
use App\Models\User\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = Company::create([
            'company_no' => 'CMP-0001',
            'company_name' => 'Test Company',
            'company_ein' => '12-3456789',
            'company_email' => 'test@company.com',
            'company_phone' => '+1-555-0100',
            'company_website' => 'https://www.testcompany.com',
            'company_entity_type' => 'Corporation',
            'company_type' => 'Technology',
            'company_description' => 'A sample seeded company used for development and testing.',
            'address_line_1' => '123 Main Street',
            'address_line_2' => 'Suite 100',
            'city' => 'Test City',
            'state' => 'CA',
            'postal_code' => '90001',
            'country' => 'USA',
            'additional_data' => null,
            'notes' => 'Seeded by CompanySeeder.',
            'currency' => 'USD',
            'years_in_business' => 10,
            'monthly_revenue' => 250000.00,
            'annual_revenue' => 3000000.00,
            'status' => Company::STATUS_ACTIVE,
            // 'assigned_agent_id' => null, // optional and nullable
        ]);

        User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'company_id' => $company?->id,
            'email' => 'admin@mail.com',
            'password' => '12345678', 
            'phone_number' => '08123456789',
            'user_type' => User::USERTYPE['Admin'],
        ]);
    }
}
