<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User\User;
use App\Models\Borrower\Borrower;

class BorrowerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('borrowers')->insert([
            [
                'signup_id' => 'SIGN-1001',
                'partner_id' => 1, 
                'business_no' => 'BN-TS-001',
                'business_name' => 'Alpha Tech Startup',
                'business_ein' => '12-3456789',
                'business_email' => 'craig-borrower@fourcornerholdings.com',
                'business_phone' => '+1234000100',
                'business_entity_type' => 'LLC',
                'business_type' => 'Software',
                'business_description' => 'AI-powered tech products',
                'business_logo' => null,
                'status' => 'active',
                'business_registration_number' => 'REG-TS-001',
                'tax_identification_number' => 'TIN-TS-001',
                'assign_id' => null,
                'home_address' => '500 Innovation Dr',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'USA',
                'additional_data' => json_encode(['employees' => 10]),
                'duns_number' => '123456789',
                'state_file_number' => 'SF-TS-001',
                'business_start_date' => '2020-01-01',
                'business_website' => 'https://alpha-tech.example.com',
                'notes' => 'Strong growth trajectory',
                'currency' => 'USD',
                'years_in_business' => 5,
                'monthly_revenue' => 120000.00,
                'annual_revenue' => 1440000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1002',
                'partner_id' => 3, // Green Energy Solutions LLC
                'business_no' => 'BN-GE-001',
                'business_name' => 'Renewable Innovators',
                'business_ein' => '98-7654321',
                'business_email' => 'contact@renewableinnovators.com',
                'business_phone' => '+1234000200',
                'business_entity_type' => 'Corporation',
                'business_type' => 'Renewable Energy',
                'business_description' => 'Solar and wind projects',
                'business_logo' => null,
                'status' => 'pending',
                'business_registration_number' => 'REG-GE-001',
                'tax_identification_number' => 'TIN-GE-001',
                'assign_id' => null,
                'home_address' => '800 Green Blvd',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60601',
                'country' => 'USA',
                'additional_data' => json_encode(['employees' => 25]),
                'duns_number' => '987654321',
                'state_file_number' => 'SF-GE-001',
                'business_start_date' => '2018-06-15',
                'business_website' => 'https://renewable-innovators.example.com',
                'notes' => 'Seeking funding for expansion',
                'currency' => 'USD',
                'years_in_business' => 7,
                'monthly_revenue' => 75000.00,
                'annual_revenue' => 900000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1003',
                'partner_id' => 2, // Tech Solutions Inc
                'business_no' => 'BN-TS-002',
                'business_name' => 'Urban Restaurant Group',
                'business_ein' => '11-2223333',
                'business_email' => 'info@urbanrestaurant.com',
                'business_phone' => '+1234000300',
                'business_entity_type' => 'LLC',
                'business_type' => 'Food & Beverage',
                'business_description' => 'Chain of urban restaurants',
                'business_logo' => null,
                'status' => 'active',
                'business_registration_number' => 'REG-UR-001',
                'tax_identification_number' => 'TIN-UR-001',
                'assign_id' => null,
                'home_address' => '100 Food Plaza',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90002',
                'country' => 'USA',
                'additional_data' => json_encode(['locations' => 5]),
                'duns_number' => '555666777',
                'state_file_number' => 'SF-UR-001',
                'business_start_date' => '2016-03-10',
                'business_website' => 'https://urban-restaurant.example.com',
                'notes' => 'Looking to expand to new cities',
                'currency' => 'USD',
                'years_in_business' => 9,
                'monthly_revenue' => 65000.00,
                'annual_revenue' => 780000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

    }
}