<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure Passport keys and clients exist, without re-running migrations
        // 1) Keys: generate only if missing
        try {
            $privateKey = storage_path('oauth-private.key');
            $publicKey = storage_path('oauth-public.key');
            if (!File::exists($privateKey) || !File::exists($publicKey)) {
                Artisan::call('passport:keys', ['--force' => true]);
            }
        } catch (\Throwable $e) {
            // Ignore key generation failures during seeding
        }

        // 2) Clients: create only if missing (Passport v12 schema)
        try {
            // Personal access client: grant_types contains "personal_access"
            $hasPersonalClient = DB::table('oauth_clients')
                ->where('revoked', false)
                ->where('grant_types', 'like', '%"personal_access"%')
                ->exists();

            if (!$hasPersonalClient) {
                Artisan::call('passport:client', [
                    '--personal' => true,
                    '--provider' => 'users',
                    '--name' => 'Default Personal Access Client',
                ]);
            }

            // Password grant client: provider = users and grant_types contains "password"
            $hasPasswordClient = DB::table('oauth_clients')
                ->where('revoked', false)
                ->where('provider', 'users')
                ->where('grant_types', 'like', '%"password"%')
                ->exists();

            if (!$hasPasswordClient) {
                Artisan::call('passport:client', [
                    '--password' => true,
                    '--provider' => 'users',
                    '--name' => 'Default Password Grant Client',
                ]);
            }
        } catch (\Throwable $e) {
            // If oauth tables aren't migrated yet, silently continue; migrate:fresh will run migrations first
        }

        // Call seeders in proper order to respect foreign key relationships
        $this->call([
            UserSeeder::class,          // First: Create users (no dependencies)
            BusinessSeeder::class,      // Second: Create businesses (no dependencies)
            EmployeeSeeder::class,      // Third: Create employees (depends on users and businesses)
            PartnerSeeder::class,       // Fourth: Create partners (depends on users, businesses, employees)
            BorrowerSeeder::class,      // Fifth: Create borrowers (depends on users, partners, businesses, employees)
            BorrowerDocTypesSeeder::class, // Seed borrower document types
            PermissionSeeder::class,      // Role and Permissions Seeder
        ]);
    }
}
