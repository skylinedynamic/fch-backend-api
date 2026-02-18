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
    use LocationHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $loc1 = $this->getRandomLocation();
        $loc1Business = $this->getRandomLocation();

        $loc2 = $this->getRandomLocation();
        $loc2Business = $this->getRandomLocation();

        $loc3 = $this->getRandomLocation();
        $loc3Business = $this->getRandomLocation();

        $loc4 = $this->getRandomLocation();
        $loc4Business = $this->getRandomLocation();

        $loc5 = $this->getRandomLocation();
        $loc5Business = $this->getRandomLocation();

        DB::table('borrowers')->insert([
            [
                'signup_id' => 'SIGN-1001',
                'partner_id' => 1,
                // Personal Info
                'first_name' => 'Michael',
                'last_name' => 'Chen',
                'email' => 'craig-borrower@fourcornerfunding.com',
                'phone_number' => '(546) 456-5465',
                'country' => $loc1['country_name'],
                'country_code' => '+1',
                'state' => $loc1['state'],
                'city' => $loc1['city'],
                'other_city' => null,
                'postal_code' => '94105',
                'home_address' => '101 Tech Way, Apt 4B',
                'home_phone' => '(546) 456-5522',
                'date_of_birth' => '1985-04-12',
                'last_4_ssn' => '1234',

                // Business Info
                'business_name' => 'Alpha Tech Solutions',
                'business_ein' => '12-3456789',
                'business_phone' => '(546) 456-4520',
                'business_address' => '500 Innovation Dr',
                'business_country' => $loc1Business['country_name'],
                'business_country_code' => '+1',
                'business_city' => $loc1Business['city'],
                'business_state' => $loc1Business['state'],
                'business_postal_code' => '94107',
                'business_email' => 'contact@alphatech.example.com',
                'business_website' => 'https://alphatech.example.com',
                'business_entity_type' => 'S-Corporation',
                'business_industry_type' => 'Accounting & Financial Services',
                'business_description' => '',
                'business_logo' => null,
                'business_registration_number' => '',
                'tax_identification_number' => '',
                'duns_number' => '',
                'state_file_number' => 'SF-1001',
                'business_start_date' => '2019-01-15',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => '6-12 months',
                'monthly_revenue' => 150000.00,
                'annual_revenue' => 1800000.00,

                'status' => 'OnBoarding',
                'assign_id' => null,
                'additional_data' => null,
                'notes' => 'High growth potential, recently closed Series A funding.',
                'current_tier' => 2,
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
                'phone_number' => '(546) 456-4521',
                'country' => $loc2['country_name'],
                'country_code' => '+1',
                'state' => $loc2['state'],
                'city' => $loc2['city'],
                'other_city' => null,
                'postal_code' => '78701',
                'home_address' => '202 Eco Lane',
                'home_phone' => '(546) 456-4522',
                'date_of_birth' => '1990-08-22',
                'last_4_ssn' => '5678',

                // Business Info
                'business_name' => 'Green Earth Innovations',
                'business_ein' => '98-7654321',
                'business_phone' => '(546) 456-4523',
                'business_address' => '800 Solar Blvd',
                'business_country' => $loc2Business['country_name'],
                'business_country_code' => '+1',
                'business_city' => $loc2Business['city'],
                'business_state' => $loc2Business['state'],
                'business_postal_code' => '78702',
                'business_email' => 'info@greenearth.example.com',
                'business_website' => 'https://greenearth.example.com',
                'business_entity_type' => '',
                'business_industry_type' => '',
                'business_description' => '',
                'business_logo' => null,
                'business_registration_number' => '',
                'tax_identification_number' => '',
                'duns_number' => '',
                'state_file_number' => 'SF-2002',
                'business_start_date' => '2020-06-10',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => '1-2 years',
                'monthly_revenue' => 85000.00,
                'annual_revenue' => 1020000.00,

                'status' => 'OnBoarding',
                'assign_id' => null,
                'additional_data' => null,
                'notes' => 'Pending final document verification.',
                'current_tier' => 1,
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
                'phone_number' => '(546) 555-5520',
                'country' => $loc3['country_name'],
                'country_code' => '+1',
                'state' => $loc3['state'],
                'city' => $loc3['city'],
                'other_city' => null,
                'postal_code' => '10001',
                'home_address' => '303 Culinary Ave, Suite 10',
                'home_phone' => '(546) 555-5521',
                'date_of_birth' => '1978-11-05',
                'last_4_ssn' => '9012',

                // Business Info
                'business_name' => 'Urban Bites Group',
                'business_ein' => '11-2233445',
                'business_phone' => '(546) 555-5520',
                'business_address' => '1500 Broadway',
                'business_country' => $loc3Business['country_name'],
                'business_country_code' => '+1',
                'business_city' => $loc3Business['city'],
                'business_state' => $loc3Business['state'],
                'business_postal_code' => '10036',
                'business_email' => 'hello@urbanbites.example.com',
                'business_website' => 'https://urbanbites.example.com',
                'business_entity_type' => '',
                'business_industry_type' => '',
                'business_description' => '',
                'business_logo' => null,
                'business_registration_number' => '',
                'tax_identification_number' => '',
                'duns_number' => '112233445',
                'state_file_number' => 'SF-3003',
                'business_start_date' => '2015-03-20',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => '1-2 years',
                'monthly_revenue' => 200000.00,
                'annual_revenue' => 2400000.00,

                'status' => 'OnBoarding',
                'assign_id' => null,
                'additional_data' => null,
                'notes' => 'Stable revenue, looking to franchise.',
                'current_tier' => 1,
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
                'phone_number' => '(546) 555-5521',
                'country' => $loc4['country_name'],
                'country_code' => '+1',
                'state' => $loc4['state'],
                'city' => $loc4['city'],
                'other_city' => null,
                'postal_code' => '60611',
                'home_address' => '404 Lake Shore Dr',
                'home_phone' => '(546) 555-5520',
                'date_of_birth' => '1982-02-14',
                'last_4_ssn' => '3456',

                // Business Info
                'business_name' => 'Summit Strategic Consulting',
                'business_ein' => '55-6677889',
                'business_phone' => '(546) 555-5521',
                'business_address' => '200 W Madison St',
                'business_country' => $loc4Business['country_name'],
                'business_country_code' => '+1',
                'business_city' => $loc4Business['city'],
                'business_state' => $loc4Business['state'],
                'business_postal_code' => '60606',
                'business_email' => 'contact@summitconsulting.example.com',
                'business_website' => 'https://summitconsulting.example.com',
                'business_entity_type' => '',
                'business_industry_type' => '',
                'business_description' => '',
                'business_logo' => null,
                'business_registration_number' => '',
                'tax_identification_number' => '',
                'duns_number' => '',
                'state_file_number' => '',
                'business_start_date' => '2012-09-01',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => '1-2 years',
                'monthly_revenue' => 180000.00,
                'annual_revenue' => 2160000.00,

                'status' => 'OnBoarding',
                'assign_id' => null,
                'additional_data' => null,
                'notes' => 'Consistent performance, low risk.',
                'current_tier' => 1,
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
                'phone_number' => '(546) 456-1122',
                'country' => $loc5['country_name'],
                'country_code' => '+1',
                'state' => $loc5['state'],
                'city' => $loc5['city'],
                'other_city' => null,
                'postal_code' => '33101',
                'home_address' => '505 Ocean Dr',
                'home_phone' => '(546) 555-5465',
                'date_of_birth' => '1975-07-30',
                'last_4_ssn' => '7890',

                // Business Info
                'business_name' => 'BuildRight Construction',
                'business_ein' => '22-3344556',
                'business_phone' => '(546) 555-5522',
                'business_address' => '700 Brickell Ave',
                'business_country' => $loc5Business['country_name'],
                'business_country_code' => '+1',
                'business_city' => $loc5Business['city'],
                'business_state' => $loc5Business['state'],
                'business_postal_code' => '33131',
                'business_email' => 'info@buildright.example.com',
                'business_website' => 'https://buildright.example.com',
                'business_entity_type' => '',
                'business_industry_type' => '',
                'business_description' => '',
                'business_logo' => null,
                'business_registration_number' => '',
                'tax_identification_number' => '',
                'duns_number' => '',
                'state_file_number' => '',
                'business_start_date' => '2008-05-15',

                // Financial Info
                'currency' => 'USD',
                'years_in_business' => '1-2 years',
                'monthly_revenue' => 450000.00,
                'annual_revenue' => 5400000.00,

                'status' => 'OnBoarding',
                'assign_id' => null,
                'additional_data' => null,
                'notes' => 'Large scale operations, seasonal revenue fluctuations.',
                'current_tier' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
