<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use Illuminate\Database\Seeder;
use App\Models\Business\Business;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cached permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Collect unique permission names from config
        $permissions = collect(config('permissions'))
            ->flatten()
            ->unique()
            ->values();

        if ($permissions->isEmpty()) {
            $this->command->warn('No permissions found in config/permissions.php');
            return;
        }

        // Fetch all businesses
        $businesses = Business::all();

        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Seed businesses first.');
            return;
        }

        // Insert permissions per business
        foreach ($businesses as $business) {
            foreach ($permissions as $name) {
                Permission::updateOrCreate(
                    [
                        'name' => $name,
                        'business_id' => $business->id,
                        'guard_name' => 'api',
                    ]
                );
            }

            $this->command->info("Permissions synced for Business ID: {$business->id}");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('All permissions seeded successfully for all businesses.');
    }
}
