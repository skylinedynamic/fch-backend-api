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
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('partners')->insert([
            [
                'is_host' => true,
                'company_name' => 'FCH Host Partner',
                'email' => 'host@fch.com',
                'phone_number' => '+1000000000',
                'company_logo' => 'logo.png',
                'full_name' => 'Host Admin',
                'contact_person' => 'Host Contact',
                'contact_person_email' => 'contact@fch.com',
                'contact_person_phone_number' => '+1000000002',
                'partner_type' => 'host',
                'state' => 'CA',
                'city' => 'San Francisco',
                'postal_code' => '94105',
                'address_line_1' => '123 Main St',
                'address_line_2' => null,
                'country' => 'USA',
                'status' => 'active',
                'assign_to' => null, // No assignment needed in seed
                'joining_date' => '2025-11-26',
                'partner_image' => 'logo.png',
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
                'partner_type' => 'agency',
                'state' => 'CA',
                'city' => 'Los Angeles',
                'postal_code' => '90001',
                'address_line_1' => '456 Oak St',
                'address_line_2' => null,
                'country' => 'USA',
                'status' => 'active',
                'assign_to' => null,
                'joining_date' => '2025-11-26',
                'partner_image' => 'logo.png',
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
                'partner_type' => 'agency',
                'state' => 'IL',
                'city' => 'Chicago',
                'postal_code' => '60601',
                'address_line_1' => '789 Pine St',
                'address_line_2' => null,
                'country' => 'USA',
                'status' => 'active',
                'assign_to' => null,
                'joining_date' => '2025-11-26',
                'partner_image' => 'logo.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}