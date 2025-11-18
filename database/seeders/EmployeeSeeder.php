<?php

namespace Database\Seeders;

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
                'partner_id' => 2, // Tech Solutions Inc
                'first_name' => 'John',
                'last_name' => 'Partner',
                'email' => 'john.partner.employee@example.com',
                'phone_number_1' => '+1234567891',
                'phone_number_2' => '+1234567892',
                'date_of_birth' => '1980-05-20',
                'gender' => 'male',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90210',
                'country' => 'USA',
                'address' => '456 Oak St',
                'ssn' => '123-45-6789',
                'image' => 'john_partner.jpg',
                'department' => 'Business Development',
                'designation' => 'Partner Manager',
                'job_status' => 'Active',
                'joining_date' => '2021-03-15',
                'identification_number' => 'EMP-0002',
                'blood_group' => 'A+',
                'place_of_birth' => 'Los Angeles',
                'emergency_contact' => 'Mary Partner',
                'emergency_phone' => '+1234567899',
                'marital_status' => 'Married',
                'no_of_children' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'partner_id' => 3, // Green Energy Solutions LLC
                'first_name' => 'Sarah',
                'last_name' => 'Wilson',
                'email' => 'sarah.wilson.employee@example.com',
                'phone_number_1' => '+1234567892',
                'phone_number_2' => '+1234567893',
                'date_of_birth' => '1988-03-12',
                'gender' => 'female',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60601',
                'country' => 'USA',
                'address' => '789 Pine St',
                'ssn' => '987-65-4321',
                'image' => 'sarah_wilson.jpg',
                'department' => 'Finance',
                'designation' => 'Chief Accountant',
                'job_status' => 'Active',
                'joining_date' => '2019-09-01',
                'identification_number' => 'EMP-0003',
                'blood_group' => 'B+',
                'place_of_birth' => 'Chicago',
                'emergency_contact' => 'Tom Wilson',
                'emergency_phone' => '+1234567800',
                'marital_status' => 'Married',
                'no_of_children' => '2',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'partner_id' => 2, // Tech Solutions Inc
                'first_name' => 'David',
                'last_name' => 'Thompson',
                'email' => 'david.thompson@techsolutions.com',
                'phone_number_1' => '+1234567896',
                'phone_number_2' => '+1234567897',
                'date_of_birth' => '1985-11-08',
                'gender' => 'male',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94105',
                'country' => 'USA',
                'address' => '404 Elm St',
                'ssn' => '456-78-9012',
                'image' => 'david_thompson.jpg',
                'department' => 'IT Support',
                'designation' => 'IT Specialist',
                'job_status' => 'Active',
                'joining_date' => '2022-08-01',
                'identification_number' => 'EMP-0007',
                'blood_group' => 'B-',
                'place_of_birth' => 'San Diego',
                'emergency_contact' => 'Maria Thompson',
                'emergency_phone' => '+1234567004',
                'marital_status' => 'Married',
                'no_of_children' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'partner_id' => 3, // Green Energy Solutions LLC
                'first_name' => 'Lisa',
                'last_name' => 'Martinez',
                'email' => 'lisa.martinez@greenenergy.com',
                'phone_number_1' => '+1234567897',
                'phone_number_2' => '+1234567898',
                'date_of_birth' => '1990-07-22',
                'gender' => 'female',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country' => 'USA',
                'address' => '505 Oak St',
                'ssn' => '789-01-2345',
                'image' => 'lisa_martinez.jpg',
                'department' => 'Human Resources',
                'designation' => 'HR Manager',
                'job_status' => 'Active',
                'joining_date' => '2021-01-10',
                'identification_number' => 'EMP-0008',
                'blood_group' => 'A-',
                'place_of_birth' => 'Austin',
                'emergency_contact' => 'Mike Martinez',
                'emergency_phone' => '+1234567005',
                'marital_status' => 'Single',
                'no_of_children' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Generate 50 employees for partner 1 and 50 employees for partner 2
        $departments = ['Operations', 'Finance', 'IT', 'HR', 'Sales', 'Support'];
        $designations = ['Staff', 'Manager', 'Lead', 'Analyst', 'Coordinator', 'Specialist'];

        $bulk = [];
        foreach ([1, 2] as $partnerId) {
            for ($i = 1; $i <= 50; $i++) {
                $firstName = "Employee{$partnerId}{$i}";
                $lastName = "User";
                $department = $departments[$i % count($departments)];
                $designation = $designations[$i % count($designations)];
                $email = "partner{$partnerId}.employee{$i}@example.com";
                $phone1 = '+100000' . str_pad((string)($partnerId * 1000 + $i), 4, '0', STR_PAD_LEFT);
                $phone2 = '+200000' . str_pad((string)($partnerId * 1000 + $i), 4, '0', STR_PAD_LEFT);

                $bulk[] = [
                    'partner_id' => $partnerId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone_number_1' => $phone1,
                    'phone_number_2' => $phone2,
                    'department' => $department,
                    'designation' => $designation,
                    'job_status' => 'Active',
                    'identification_number' => sprintf('EMP-P%d-%03d', $partnerId, $i),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('employees')->insert($bulk);
    }
}