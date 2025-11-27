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
                // Personal Info
                'first_name' => 'Michael',
                'last_name' => 'Chen',
                'email' => 'craig-borrower@fourcornerholdings.com',
                'phone_number' => '5550101001',
                'country' => 'United States',
                'country_code' => '+1',
                'state' => 'New York',
                'city' => 'New York City',
                'postal_code' => '94105',
                'home_address' => '101 Tech Way, Apt 4B',
                'home_phone' => '5550101002',
                'date_of_birth' => '1985-04-12',
                'last_4_ssn' => '1234',

                // Business Info
                'business_name' => 'Alpha Tech Solutions',
                'business_ein' => '12-3456789',
                'business_phone' => '5550101000',
                'business_address' => '500 Innovation Dr',
                'business_city' => 'New York City',
                'business_state' => 'New York',
                'business_postal_code' => '94107',
                'business_email' => 'contact@alphatech.example.com',
                'business_website' => 'https://alphatech.example.com',
                'business_entity_type' => 'LLC',
                'business_industry_type' => 'Software',
                'business_description' => 'AI-powered enterprise software solutions.',
                'business_logo' => null,
                'business_registration_number' => 'REG-CA-1001',
                'tax_identification_number' => 'TIN-1001',
                'duns_number' => '123456789',
                'state_file_number' => 'SF-1001',
                'business_start_date' => '2019-01-15',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => 6,
                'monthly_revenue' => 150000.00,
                'annual_revenue' => 1800000.00,

                'status' => 'active',
                'assign_id' => null,
                'additional_data' => json_encode(['employees' => 15, 'funding_round' => 'Series A']),
                'notes' => 'High growth potential, recently closed Series A funding.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1002',
                'partner_id' => 3,
                // Personal Info
                'first_name' => 'Sarah',
                'last_name' => 'Jenkins',
                'email' => 'sarah.j@greenearth.example.com',
                'phone_number' => '5550202001',
                'country' => 'United States',
                'country_code' => '+1',
                'state' => 'New York',
                'city' => 'New York City',
                'postal_code' => '78701',
                'home_address' => '202 Eco Lane',
                'home_phone' => '5550202002',
                'date_of_birth' => '1990-08-22',
                'last_4_ssn' => '5678',

                // Business Info
                'business_name' => 'Green Earth Innovations',
                'business_ein' => '98-7654321',
                'business_phone' => '+15550202000',
                'business_address' => '800 Solar Blvd',
                'business_city' => 'New York City',
                'business_state' => 'New York',
                'business_postal_code' => '78702',
                'business_email' => 'info@greenearth.example.com',
                'business_website' => 'https://greenearth.example.com',
                'business_entity_type' => 'Corporation',
                'business_industry_type' => 'Energy',
                'business_description' => 'Renewable energy consulting and installation.',
                'business_logo' => null,
                'business_registration_number' => 'REG-TX-2002',
                'tax_identification_number' => 'TIN-2002',
                'duns_number' => '987654321',
                'state_file_number' => 'SF-2002',
                'business_start_date' => '2020-06-10',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => 5,
                'monthly_revenue' => 85000.00,
                'annual_revenue' => 1020000.00,

                'status' => 'pending',
                'assign_id' => null,
                'additional_data' => json_encode(['employees' => 8, 'certifications' => ['LEED', 'SolarPro']]),
                'notes' => 'Pending final document verification.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1003',
                'partner_id' => 2,
                // Personal Info
                'first_name' => 'David',
                'last_name' => 'Rossi',
                'email' => 'david.rossi@urbanbites.example.com',
                'phone_number' => '5550303001',
                'country' => 'United States',
                'country_code' => '+1',
                'state' => 'New York',
                'city' => 'New York City',
                'postal_code' => '10001',
                'home_address' => '303 Culinary Ave, Suite 10',
                'home_phone' => '5550303002',
                'date_of_birth' => '1978-11-05',
                'last_4_ssn' => '9012',

                // Business Info
                'business_name' => 'Urban Bites Group',
                'business_ein' => '11-2233445',
                'business_phone' => '5550303000',
                'business_address' => '1500 Broadway',
                'business_city' => 'New York City',
                'business_state' => 'New York',
                'business_postal_code' => '10036',
                'business_email' => 'hello@urbanbites.example.com',
                'business_website' => 'https://urbanbites.example.com',
                'business_entity_type' => 'LLC',
                'business_industry_type' => 'Food & Beverage',
                'business_description' => 'Fast-casual restaurant chain specializing in organic bowls.',
                'business_logo' => null,
                'business_registration_number' => 'REG-NY-3003',
                'tax_identification_number' => 'TIN-3003',
                'duns_number' => '112233445',
                'state_file_number' => 'SF-3003',
                'business_start_date' => '2015-03-20',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => 10,
                'monthly_revenue' => 200000.00,
                'annual_revenue' => 2400000.00,

                'status' => 'active',
                'assign_id' => null,
                'additional_data' => json_encode(['locations' => 3, 'franchise_ready' => true]),
                'notes' => 'Stable revenue, looking to franchise.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1004',
                'partner_id' => 1,
                // Personal Info
                'first_name' => 'Emily',
                'last_name' => 'Wong',
                'email' => 'emily.wong@summitconsulting.example.com',
                'phone_number' => '5550404001',
                'country' => 'United States',
                'country_code' => '+1',
                'state' => 'New York',
                'city' => 'New York City',
                'postal_code' => '60611',
                'home_address' => '404 Lake Shore Dr',
                'home_phone' => '5550404002',
                'date_of_birth' => '1982-02-14',
                'last_4_ssn' => '3456',

                // Business Info
                'business_name' => 'Summit Strategic Consulting',
                'business_ein' => '55-6677889',
                'business_phone' => '5550404000',
                'business_address' => '200 W Madison St',
                'business_city' => 'New York City',
                'business_state' => 'New York',
                'business_postal_code' => '60606',
                'business_email' => 'contact@summitconsulting.example.com',
                'business_website' => 'https://summitconsulting.example.com',
                'business_entity_type' => 'Partnership',
                'business_industry_type' => 'Consulting',
                'business_description' => 'Management consulting for mid-sized enterprises.',
                'business_logo' => null,
                'business_registration_number' => 'REG-IL-4004',
                'tax_identification_number' => 'TIN-4004',
                'duns_number' => '556677889',
                'state_file_number' => 'SF-4004',
                'business_start_date' => '2012-09-01',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => 13,
                'monthly_revenue' => 180000.00,
                'annual_revenue' => 2160000.00,

                'status' => 'active',
                'assign_id' => null,
                'additional_data' => json_encode(['partners' => 3, 'key_clients' => 12]),
                'notes' => 'Consistent performance, low risk.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'signup_id' => 'SIGN-1005',
                'partner_id' => 2,
                // Personal Info
                'first_name' => 'Robert',
                'last_name' => 'Miller',
                'email' => 'robert.miller@buildright.example.com',
                'phone_number' => '5550505001',
                'country' => 'United States',
                'country_code' => '+1',
                'state' => 'New York',
                'city' => 'New York City',
                'postal_code' => '33101',
                'home_address' => '505 Ocean Dr',
                'home_phone' => '5550505002',
                'date_of_birth' => '1975-07-30',
                'last_4_ssn' => '7890',

                // Business Info
                'business_name' => 'BuildRight Construction',
                'business_ein' => '22-3344556',
                'business_phone' => '5550505000',
                'business_address' => '700 Brickell Ave',
                'business_city' => 'New York City',
                'business_state' => 'New York',
                'business_postal_code' => '33131',
                'business_email' => 'info@buildright.example.com',
                'business_website' => 'https://buildright.example.com',
                'business_entity_type' => 'Corporation',
                'business_industry_type' => 'Construction',
                'business_description' => 'Commercial and residential construction services.',
                'business_logo' => null,
                'business_registration_number' => 'REG-FL-5005',
                'tax_identification_number' => 'TIN-5005',
                'duns_number' => '223344556',
                'state_file_number' => 'SF-5005',
                'business_start_date' => '2008-05-15',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => 17,
                'monthly_revenue' => 450000.00,
                'annual_revenue' => 5400000.00,

                'status' => 'active',
                'assign_id' => null,
                'additional_data' => json_encode(['projects_completed' => 150, 'safety_rating' => 'A+']),
                'notes' => 'Large scale operations, seasonal revenue fluctuations.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}