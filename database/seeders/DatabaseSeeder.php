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

        // Call seeders in an order that respects new FK relationships
        $this->call([
            CreditBuilderVendorSeeder::class,
            TaskRelatedApiSeeder::class,
            TierSeeder::class, // Create tiers and tier tasks
            PartnerSeeder::class, // Create partners (first is host)
            BorrowerSeeder::class, // Create borrowers under partners
            UserSeeder::class, // Create users mapped to partner/borrower/employee entities
            BorrowerDocTypesSeeder::class, // Seed borrower document types
            PermissionSeeder::class, // Role and Permissions seeder
            RoleSeeder::class, // Create roles under partners
            UserRoleSeeder::class, // Assign roles to users and sync permissions
            EmployeeSeeder::class, // Create employees under partners
            DepartmentSeeder::class, // Create departments under partners
            AppointmentSeeder::class, // Create appointments for partners
                // InvoiceSeeder::class,            // Create invoices for partners
            SupportTicketSeeder::class, // Create support tickets for partners
            BorrowerTaskVerificationRequestSeeder::class,
            BorrowerTaskVerificationRequestSeeder::class,
            ContactSeeder::class,
            LeadSeeder::class,
            SignupIdSeeder::class,
            SubscriptionPlanSeeder::class,
            DemoSubscriberSeeder::class,
            CreditAccountSeeder::class,
            ResourceSeeder::class,
            AppConfigurationsSeeder::class,
            EmailConfigurationSeeder::class,
            BlogSeeder::class,
            SeoSettingSeeder::class,
            CmsPermissionUpdateSeeder::class,
            UpdateSettingsPermissionSeeder::class,
            PaymentProviderSeeder::class,
            LenderProductCategorySeeder::class,
            LenderSeeder::class,
            FundingSequencingSeeder::class,
            LenderFundingPermissionSeeder::class,
            FundingRequestPermissionSeeder::class,
            UpdateDomainConfigurationPermissionSeeder::class,
            AdditionalSeatForUserSeeder::class,
            SubscriptionSeatsSeeder::class,
            TaskReminderPermissionSeeder::class,
            SmsConfigurationPermissionSeeder::class,
        ]);
    }
}



// php artisan db:seed --class=SmsConfigurationPermissionSeeder
// php artisan db:seed --class=LinkUsersToEmployeesSeeder

