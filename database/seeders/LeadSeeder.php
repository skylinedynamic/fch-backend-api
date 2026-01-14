<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Lead\Lead;
use Faker\Factory as Faker;

class LeadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        // Generate 50 sample leads
        for ($i = 0; $i < 50; $i++) {
            $leadId = 'LD-' . now()->format('Y-md') . '-' . str_pad(($i + 1), 3, '0', STR_PAD_LEFT);
            Lead::create([
                'lead_id' => $leadId,
                'lead_type' => $faker->randomElement(['Borrower', 'Partner']),
                'partner_id' => $faker->numberBetween(1, 3),
                'status' => $faker->randomElement(['new', 'contacted', 'qualified', 'lost']),
                'salesperson_id' => null,
                'company_name' => $faker->company,
                'domain' => $faker->domainName,
                'lead_source' => $faker->word,
                'employees' => $faker->numberBetween(1, 500),
                'contact_person' => $faker->name,
                'phone_number' => $faker->phoneNumber,
                'email' => $faker->unique()->safeEmail,
                'legal_business_name' => $faker->company,
                'business_ein' => $faker->bothify('##-#######'),
                'state_file_number' => $faker->bothify('##-####'),
                'duns_number' => $faker->bothify('##########'),
                'business_address' => $faker->address,
                'country' => 'USA',
                'city' => $faker->city,
                'state' => $faker->stateAbbr,
                'zip_code' => $faker->postcode,
                'business_phone' => $faker->phoneNumber,
                'entity_type' => $faker->randomElement(['LLC', 'Corporation', 'Partnership']),
                'industry_type' => $faker->word,
                'business_start_date' => $faker->date(),
                'ssn_last_4' => $faker->randomNumber(4, true),
                'requirement_details' => $faker->paragraph,
                'created_by' => null,
                'updated_by' => null,
            ]);
        }
    }
}
