<?php

namespace Database\Seeders;

use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            ['Phoenix', 'AZ'], ['Atlanta', 'GA'], ['Charlotte', 'NC'],
        ];
        $streets = ['Oak', 'Pine', 'Elm', 'Maple', 'Cedar', 'Walnut', 'Birch', 'Ash', 'Cherry', 'Willow'];
        $genders = ['male', 'female'];

        // Get all partners
        $partners = DB::table('partners')->pluck('id');

        foreach ($partners as $partnerId) {
            // Get the Sales role for this partner
            $salesRole = Role::where('name', 'Sales')
                ->where('partner_id', $partnerId)
                ->where('guard_name', 'api')
                ->first();

            if (! $salesRole) {
                $this->command?->warn("Sales role not found for Partner ID {$partnerId}. Skipping employees for this partner.");

                continue;
            }

            for ($i = 1; $i <= 10; $i++) {
                $firstName = "Employee{$partnerId}{$i}";
                $lastName = 'User';
                $empCode = "Emp-0000{$i}";
                $department = $departments[$i % count($departments)];
                $designation = $designations[$i % count($designations)];
                $email = "partner{$partnerId}.employee{$i}@example.com";
                $number = $partnerId * 1000 + $i;

                $phone1 = str_pad($number, 10, '0', STR_PAD_LEFT);
                $phone2 = str_pad($number, 10, '0', STR_PAD_LEFT);

                // Random profile fields
                $gender = $genders[array_rand($genders)];
                $cs = $citiesStates[array_rand($citiesStates)];
                $city = $cs[0];
                $state = $cs[1];
                $postal = str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                $country = 'USA';
                $address = mt_rand(100, 999).' '.$streets[array_rand($streets)].' St';
                $image = "employee_{$partnerId}_{$i}.jpg";
                $dobTs = mt_rand(strtotime('1970-01-01'), strtotime('2002-12-31'));
                $dateOfBirth = date('Y-m-d', $dobTs);
                $joinTs = mt_rand(strtotime('2018-01-01'), strtotime('2024-12-31'));
                $joiningDate = date('Y-m-d', $joinTs);
                $emergencyContact = $firstName.' '.$lastName.' Contact';
                $emergencyPhone = str_pad($number, 10, '0', STR_PAD_LEFT);
                $countryCode = '+1';

                // Create employee
                $employee = Employee::create([
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
                    'country_code' => $countryCode,
                    'address' => $address,
                    'image' => null,
                    'department' => $department,
                    'designation' => $designation,
                    'job_status' => 'Active',
                    'joining_date' => $joiningDate,
                    'emergency_contact' => $emergencyContact,
                    'emergency_phone' => $emergencyPhone,
                ]);

                // Create user for the employee
                $user = User::create([
                    'name' => $firstName.' '.$lastName,
                    'email' => $email,
                    'phone_number' => $phone1,
                    'phone_verified_at' => null,
                    'email_verified_at' => now(),
                    'user_type' => 'Admin',
                    'status' => 'active',
                    'password' => Hash::make('12345678'),
                    'partner_id' => $partnerId,
                    'entity_type' => 'App\Models\Employee\Employee',
                    'entity_id' => $employee->id,
                ]);

                // Assign Sales role to the user
                $user->assignRole($salesRole);
            }
        }

        $this->command?->info('EmployeeSeeder completed successfully.');
    }
}
