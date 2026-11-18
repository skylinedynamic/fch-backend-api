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
            // Roles per partner: Admin, Manager, Sales, ... (and Developer for the host only)
            // The one Developer role is the host's; no other partner gets one.
            $developerRole = $partner->is_host
                ? Role::updateOrCreate(
                    ['name' => Role::NAME_DEVELOPER, 'guard_name' => 'api', 'partner_id' => $partner->id],
                    ['name' => Role::NAME_DEVELOPER, 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => Role::CODE_DEVELOPER, 'is_static' => true]
                )
                : null;

            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'admin','is_static' => true]
            );

            Role::updateOrCreate(
                ['name' => 'Manager', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Manager', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'manager','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Underwriter', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Underwriter', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'underwriter','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Credit Analyst', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Credit Analyst', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'credit_analyst','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Funding Assistant', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Funding Assistant', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'funding_assistant','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'sales','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Support', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Support', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'support','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Partner Success', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Partner Success', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'partner_success','is_static' => false]
            );

            Role::updateOrCreate(
                ['name' => 'Document Specialist', 'guard_name' => 'api', 'partner_id' => $partner->id],
                ['name' => 'Document Specialist', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'document_specialist','is_static' => false]
            );

            // Manager: assign a random subset (~40% of all permissions, min 5)
            // $managerCount = max(5, (int) floor($allPerms->count() * 0.4));
            // $managerCount = min($managerCount, $allPerms->count());
            // $managerPerms = $allPerms->shuffle()->take($managerCount);

            // // Sales: assign a random subset (~20% of all permissions, min 3)
            // $salesCount = max(3, (int) floor($allPerms->count() * 0.2));
            // $salesCount = min($salesCount, $allPerms->count());
            // $salesPerms = $allPerms->shuffle()->take($salesCount);

            // Developer: all permissions. Admin: all but the developer-only
            // ones (Business Insights), which no role but Developer holds.
            $developerRole?->syncPermissions($allPerms);

            $adminRole->syncPermissions($allPerms->reject(fn (Permission $permission) => $permission->is_developer));

            // $managerRole->syncPermissions($managerPerms);
            // $salesRole->syncPermissions($salesPerms);

            $this->command?->info(
                "Seeded roles for Partner ID {$partner->id}" . ($developerRole ? ": Developer (all)" : "") . ", Admin (all but developer-only)"
            );
        }

        // Clear cached permissions after role updates
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('RoleSeeder completed for all partners.');
    }
}