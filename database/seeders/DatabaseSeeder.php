<?php

namespace Database\Seeders;

use App\Models\Company\Company;
use App\Models\User\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call seeders in proper order to respect foreign key relationships
        $this->call([
            UserSeeder::class,          // First: Create users (no dependencies)
            BusinessSeeder::class,      // Second: Create businesses (no dependencies)
            EmployeeSeeder::class,      // Third: Create employees (depends on users and businesses)
            PartnerSeeder::class,       // Fourth: Create partners (depends on users, businesses, employees)
            BorrowerSeeder::class,      // Fifth: Create borrowers (depends on users, partners, businesses, employees)
        ]);
    }
}
