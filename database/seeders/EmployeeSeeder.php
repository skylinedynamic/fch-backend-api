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
        // Generate 10 employees for every partner (with full random profile fields)
        $departments = ['Operations', 'Finance', 'IT', 'HR', 'Sales', 'Support'];
        $designations = ['Staff', 'Manager', 'Lead', 'Analyst', 'Coordinator', 'Specialist'];
        $citiesStates = [
            ['Los Angeles', 'CA'], ['San Francisco', 'CA'], ['Austin', 'TX'], ['Chicago', 'IL'],
            ['New York', 'NY'], ['Miami', 'FL'], ['Seattle', 'WA'], ['Denver', 'CO'],
            ['Phoenix', 'AZ'], ['Atlanta', 'GA'], ['Charlotte', 'NC']
        ];
        $streets = ['Oak', 'Pine', 'Elm', 'Maple', 'Cedar', 'Walnut', 'Birch', 'Ash', 'Cherry', 'Willow'];
        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
        $genders = ['male', 'female'];
        $maritalStatuses = ['Single', 'Married'];

        $bulk = [];
        foreach (DB::table('partners')->pluck('id') as $partnerId) {
            for ($i = 1; $i <= 10; $i++) {
                $firstName = "Employee{$partnerId}{$i}";
                $lastName = "User";
                $empCode = "Emp-{$i}";
                $department = $departments[$i % count($departments)];
                $designation = $designations[$i % count($designations)];
                $email = "partner{$partnerId}.employee{$i}@example.com";
                $phone1 = '+100000' . str_pad((string)($partnerId * 1000 + $i), 4, '0', STR_PAD_LEFT);
                $phone2 = '+200000' . str_pad((string)($partnerId * 1000 + $i), 4, '0', STR_PAD_LEFT);

                // Random profile fields
                $gender = $genders[array_rand($genders)];
                $cs = $citiesStates[array_rand($citiesStates)];
                $city = $cs[0];
                $state = $cs[1];
                $postal = str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                $country = 'USA';
                $address = mt_rand(100, 999) . ' ' . $streets[array_rand($streets)] . ' St';
                $ssn = sprintf('%03d-%02d-%04d', mt_rand(100, 999), mt_rand(10, 99), mt_rand(1000, 9999));
                $image = "employee_{$partnerId}_{$i}.jpg";
                $dobTs = mt_rand(strtotime('1970-01-01'), strtotime('2002-12-31'));
                $dateOfBirth = date('Y-m-d', $dobTs);
                $joinTs = mt_rand(strtotime('2018-01-01'), strtotime('2024-12-31'));
                $joiningDate = date('Y-m-d', $joinTs);
                $bloodGroup = $bloodGroups[array_rand($bloodGroups)];
                $placeOfBirth = $city;
                $emergencyContact = $firstName . ' ' . $lastName . ' Contact';
                $emergencyPhone = '+300000' . str_pad((string) ($partnerId * 1000 + $i), 4, '0', STR_PAD_LEFT);
                $maritalStatus = $maritalStatuses[array_rand($maritalStatuses)];
                $noOfChildren = (string) mt_rand(0, 3);

                $bulk[] = [
                    'partner_id' => $partnerId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'employee_code' => $empCode,
                    'email' => $email,
                    'phone_number_1' => $phone1,
                    'phone_number_2' => $phone2,
                    'date_of_birth' => $dateOfBirth,
                    'gender' => $gender,
                    'city' => $city,
                    'state' => $state,
                    'postal_code' => $postal,
                    'country' => $country,
                    'address' => $address,
                    'ssn' => $ssn,
                    'image' => $image,
                    'department' => $department,
                    'designation' => $designation,
                    'job_status' => 'Active',
                    'joining_date' => $joiningDate,
                    'identification_number' => sprintf('EMP-P%d-%03d', $partnerId, $i),
                    'blood_group' => $bloodGroup,
                    'place_of_birth' => $placeOfBirth,
                    'emergency_contact' => $emergencyContact,
                    'emergency_phone' => $emergencyPhone,
                    'marital_status' => $maritalStatus,
                    'no_of_children' => $noOfChildren,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('employees')->insert($bulk);
    }
}
