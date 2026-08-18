<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class UpdatePaymentInvoicePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Forget cached permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2. Remove specified permissions from database (roles, models, and permissions table)
        $permissionsToRemove = [
            'create payments',
            'update payments',
            'delete payments',
            'create invoices',
            'update invoices',
            'delete invoices',
        ];

        $oldPermissions = Permission::whereIn('name', $permissionsToRemove)->get();

        foreach ($oldPermissions as $permission) {
            // Delete pivot relationships
            DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();

            $this->command?->info("Removed permission assignment for: {$permission->name}");

            // Delete permission record
            $permission->delete();
        }

        // 3. Ensure 'can approve payment' permission exists in DB by reading from config('permissions')
        $paymentsConfig = config('permissions.payments.permissions', []);
        $newPermissionName = 'can approve payment';

        $paymentPermissionData = collect($paymentsConfig)->first(function ($item) use ($newPermissionName) {
            return is_array($item) ? ($item['name'] === $newPermissionName) : ($item === $newPermissionName);
        });

        $isCertified = is_array($paymentPermissionData) ? ($paymentPermissionData['is_certified'] ?? 0) : 0;
        $isHost = is_array($paymentPermissionData) ? ($paymentPermissionData['is_host'] ?? 0) : 0;

        $newPermission = Permission::updateOrCreate(
            [
                'name' => $newPermissionName,
                'guard_name' => 'api',
            ],
            [
                'module' => 'payments',
                'is_host' => $isHost,
                'is_certified' => $isCertified,
            ]
        );

        $this->command?->info("Created/Updated permission: {$newPermissionName}");

        // 4. Assign 'can approve payment' to all Admin roles, Developer roles, and associated users
        $roles = Role::whereIn('code', ['admin', 'developer'])
            ->orWhereIn('name', ['Admin', 'Developer'])
            ->orWhere('id', 1)
            ->get();

        $this->command?->info("Found {$roles->count()} Admin/Developer role(s) to grant permission.");

        foreach ($roles as $role) {
            // Give permission to role if not already granted
            if (!$role->hasPermissionTo($newPermissionName)) {
                $role->givePermissionTo($newPermissionName);
            }

            $this->command?->info("Granted '{$newPermissionName}' to Role: {$role->name} (ID: {$role->id}, Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

            // Assign permission to users having this role (model_has_permissions)
            $modelAssignments = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->get();

            foreach ($modelAssignments as $assignment) {
                $modelClass = $assignment->model_type;
                $modelId = $assignment->model_id;

                try {
                    $user = $modelClass::find($modelId);
                    if ($user && method_exists($user, 'givePermissionTo')) {
                        if (!$user->hasPermissionTo($newPermissionName)) {
                            $user->givePermissionTo($newPermissionName);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->command?->error("Failed to assign permission to model {$modelClass} ID {$modelId}: " . $e->getMessage());
                }
            }
        }

        // 5. Force refresh user states and clear permission cache
        DB::table('users')->update(['is_refresh' => 1]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✅ UpdatePaymentInvoicePermissionSeeder completed successfully!');
    }
}
