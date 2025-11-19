<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $users = User::query()->whereIn('user_type', [User::ADMIN, User::PARTNER])->get();
        foreach ($users as $user) {
            if (!$user->partner_id) {
                continue;
            }

            $guard = 'api';

            $adminRole = Role::query()
                ->where('name', 'Admin')
                ->where('guard_name', $guard)
                ->where('partner_id', $user->partner_id)
                ->first();

            $managerRole = Role::query()
                ->where('name', 'Manager')
                ->where('guard_name', $guard)
                ->where('partner_id', $user->partner_id)
                ->first();

            $salesRole = Role::query()
                ->where('name', 'Sales')
                ->where('guard_name', $guard)
                ->where('partner_id', $user->partner_id)
                ->first();

            if (!$adminRole || !$managerRole || !$salesRole) {
                continue;
            }

     
            $isPartnerEntity = $user->entity_type === Partner::class;

            $rolesToAssign = $isPartnerEntity
                ? [$adminRole]
                : [$managerRole, $salesRole];

            $user->syncRoles($rolesToAssign);

            $permissions = collect($rolesToAssign)
                ->flatMap(fn($role) => $role->permissions)
                ->unique('id')
                ->values();

            $user->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}