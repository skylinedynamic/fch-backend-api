<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User\User;
use App\Models\Partner\Partner;

class PartnerSeeder extends Seeder
{
    use LocationHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $loc1 = $this->getRandomLocation();
        $loc2 = $this->getRandomLocation();
        $loc3 = $this->getRandomLocation();

        DB::table('partners')->insert([
            [
                'is_host' => true,
                'company_name' => 'Four Corner Funding',
                'email' => 'host@fch.com',
                'phone_number' => '+1000000000',
                'company_logo' => 'logo.png',
                'full_name' => 'Host Admin',
                'contact_person' => 'Host Contact',
                'contact_person_email' => 'contact@fch.com',
                'contact_person_phone_number' => '+1000000002',
                'partner_type' => 'White-Label Partner (WL)',
                'state' => $loc1['state'],
                'city' => $loc1['city'],
                'postal_code' => '94105',
                'address_line_1' => '123 Main St',
                'address_line_2' => null,
                'country' => $loc1['country'],
                'country_code' => '+1',
                'status' => Partner::STATUS_ACTIVE,
                'assign_to' => null, // No assignment needed in seed
                'joining_date' => '2025-11-26',
                'partner_image' => null,
                'website' => 'https://fch.com',
                'total_free_users' => null,
                'total_paid_users' => null,
                'tax_id' => '123456789',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'is_host' => false,
                'company_name' => 'Tech Solutions Inc',
                'email' => 'contact@techsolutions.com',
                'phone_number' => '+1234567800',
                'company_logo' => 'logo.png',
                'full_name' => 'John Partner',
                'contact_person' => 'John P.',
                'contact_person_email' => 'john.p@example.com',
                'contact_person_phone_number' => '+1234567892',
                'partner_type' => 'White-Label Partner (WL)',
                'state' => $loc2['state'],
                'city' => $loc2['city'],
                'postal_code' => '90001',
                'address_line_1' => '456 Oak St',
                'address_line_2' => null,
                'country' => $loc2['country'],
                'country_code' => '+1',
                'status' => Partner::STATUS_ACTIVE,
                'assign_to' => null,
                'joining_date' => '2025-11-26',
                'partner_image' => null,
                'website' => 'https://fch.com',
                'total_free_users' =>  config('app.for_free_white_level_count'),
                'total_paid_users' => 0,
                'tax_id' => '123456789',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'is_host' => false,
                'company_name' => 'Green Energy Solutions LLC',
                'email' => 'info@greenenergy.com',
                'phone_number' => '+1234567801',
                'company_logo' => 'logo.png',
                'full_name' => 'Sarah Wilson',
                'contact_person' => 'Sarah W.',
                'contact_person_email' => 'sarah.w@example.com',
                'contact_person_phone_number' => '+1234567893',
                'partner_type' => 'Certified Partner',
                'state' => $loc3['state'],
                'city' => $loc3['city'],
                'postal_code' => '60601',
                'address_line_1' => '789 Pine St',
                'address_line_2' => null,
                'country' => $loc3['country'],
                'country_code' => '+1',
                'status' => Partner::STATUS_ACTIVE,
                'assign_to' => null,
                'joining_date' => '2025-11-26',
                'partner_image' => null,
                'website' => 'https://fch.com',
                'total_free_users' =>  config('app.for_free_certified_user_count'),
                'total_paid_users' => 0,
                'tax_id' => '123456789',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
