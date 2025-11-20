<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear cached permissions before role updates
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ensure we have partners to attach roles to
        $partners = Partner::all();
        if ($partners->isEmpty()) {
            $this->command?->warn('No partners found. Seed partners first.');
            return;
        }

        // Resolve all API-guard permissions
        $allPerms = Permission::where('guard_name', 'api')->get();
        if ($allPerms->isEmpty()) {
            $this->command?->warn('No permissions found. Run PermissionSeeder first.');
            return;
        }

        foreach ($partners as $partner) {
            // Create three roles per partner: Admin, Manager, Sales
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'admin','is_static' => true]
            );

            $managerRole = Role::updateOrCreate(
                ['name' => 'Manager', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Manager', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'manager','is_static' => false]
            );

            $salesRole = Role::updateOrCreate(
                ['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'sales','is_static' => false]
            );

            // Manager: assign a random subset (~40% of all permissions, min 5)
            $managerCount = max(5, (int) floor($allPerms->count() * 0.4));
            $managerCount = min($managerCount, $allPerms->count());
            $managerPerms = $allPerms->shuffle()->take($managerCount);

            // Sales: assign a random subset (~20% of all permissions, min 3)
            $salesCount = max(3, (int) floor($allPerms->count() * 0.2));
            $salesCount = min($salesCount, $allPerms->count());
            $salesPerms = $allPerms->shuffle()->take($salesCount);

            // Admin: all permissions
            $adminRole->syncPermissions($allPerms);
            $managerRole->syncPermissions($managerPerms);
            $salesRole->syncPermissions($salesPerms);

            $this->command?->info(
                "Seeded roles for Partner ID {$partner->id}: Admin (all), Manager (subset), Sales (random)"
            );
        }

        // Clear cached permissions after role updates
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('RoleSeeder completed for all partners.');
    }
}