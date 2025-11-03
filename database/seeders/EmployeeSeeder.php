<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('employees')->insert([
            [
                'user_id' => 2, // John Partner
                'business_id' => 1, // Tech Solutions Inc
                'first_name' => 'John',
                'last_name' => 'Partner',
                'email' => 'john.partner@example.com',
                'phone_number' => '+1234567891',
                'date_of_birth' => '1980-05-20',
                'gender' => 'male',
                'city' => 'Los Angeles',
                'sate' => 'CA', // Note: keeping 'sate' as per migration schema
                'postal_code' => '90210',
                'country' => 'USA',
                'ssn' => '123-45-6789',
                'image' => 'john_partner.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 3, // Sarah Wilson
                'business_id' => 2, // Green Energy Solutions LLC
                'first_name' => 'Sarah',
                'last_name' => 'Wilson',
                'email' => 'sarah.wilson@example.com',
                'phone_number' => '+1234567892',
                'date_of_birth' => '1988-03-12',
                'gender' => 'female',
                'city' => 'Chicago',
                'sate' => 'IL',
                'postal_code' => '60601',
                'country' => 'USA',
                'ssn' => '987-65-4321',
                'image' => 'sarah_wilson.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => null, // Employee without user account
                'business_id' => 1, // Tech Solutions Inc
                'first_name' => 'David',
                'last_name' => 'Thompson',
                'email' => 'david.thompson@techsolutions.com',
                'phone_number' => '+1234567896',
                'date_of_birth' => '1985-11-08',
                'gender' => 'male',
                'city' => 'San Francisco',
                'sate' => 'CA',
                'postal_code' => '94105',
                'country' => 'USA',
                'ssn' => '456-78-9012',
                'image' => 'david_thompson.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => null, // Employee without user account
                'business_id' => 2, // Green Energy Solutions LLC
                'first_name' => 'Lisa',
                'last_name' => 'Martinez',
                'email' => 'lisa.martinez@greenenergy.com',
                'phone_number' => '+1234567897',
                'date_of_birth' => '1990-07-22',
                'gender' => 'female',
                'city' => 'Austin',
                'sate' => 'TX',
                'postal_code' => '78701',
                'country' => 'USA',
                'ssn' => '789-01-2345',
                'image' => 'lisa_martinez.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => null, // Employee without user account
                'business_id' => 3, // Urban Restaurant Group
                'first_name' => 'James',
                'last_name' => 'Rodriguez',
                'email' => 'james.rodriguez@urbanrestaurants.com',
                'phone_number' => '+1234567898',
                'date_of_birth' => '1987-04-15',
                'gender' => 'male',
                'city' => 'New York',
                'sate' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
                'ssn' => '234-56-7890',
                'image' => 'james_rodriguez.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => null, // Employee without user account
                'business_id' => 4, // Digital Marketing Pro
                'first_name' => 'Amanda',
                'last_name' => 'Chen',
                'email' => 'amanda.chen@digitalmarketingpro.com',
                'phone_number' => '+1234567899',
                'date_of_birth' => '1992-12-03',
                'gender' => 'female',
                'city' => 'Chicago',
                'sate' => 'IL',
                'postal_code' => '60601',
                'country' => 'USA',
                'ssn' => '345-67-8901',
                'image' => 'amanda_chen.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}