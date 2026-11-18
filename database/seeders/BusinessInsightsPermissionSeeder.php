<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionName;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the developer-only permissions (the Business Insights group) and gives
 * them to the one Developer role (the host's) and the users holding it. A
 * Developer may pass them on to other host roles and users; those keep them.
 * Any other partner's role or user that has one loses it. Safe to run again.
 *
 *     php artisan migrate
 *     php artisan db:seed --class=BusinessInsightsPermissionSeeder
 */
class BusinessInsightsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(PermissionName::cases())
            ->filter(fn (PermissionName $case) => $case->isDeveloper())
            ->map(fn (PermissionName $case) => Permission::updateOrCreate(
                ['name' => $case->value, 'guard_name' => 'api'],
                [
                    'module' => $case->module()->value,
                    'is_host' => (int) $case->isHost(),
                    'is_certified' => (int) $case->isCertified(),
                    'is_developer' => true,
                ]
            ))
            ->values();

        $permissionIds = $permissions->pluck('id');
        $affectedUserIds = [];

        // Eager-load `permissions`: givePermissionTo() reads it and lazy
        // loading is disabled in this app.
        // The one Developer role - the host's (a partner's stray "developer"
        // role from an old seed gets nothing, and loses any it has, below).
        $developerRoles = Role::with('permissions')
            ->where('guard_name', 'api')
            ->theDeveloperRole()
            ->get();

        foreach ($developerRoles as $role) {
            $role->givePermissionTo($permissions);

            // Some screens read direct user permissions, so mirror the grant.
            $users = User::with('permissions')
                ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
                ->get();

            foreach ($users as $user) {
                $user->givePermissionTo($permissions);
                $affectedUserIds[] = $user->id;
            }
        }

        // Only the host's roles and users may hold them (a Developer may have
        // given them to other host roles - those keep them). Another partner's
        // role or user holding one - by hand, or from an older seed - loses it.
        $hostPartnerIds = Partner::where('is_host', true)->pluck('id');

        $otherRoleIds = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->whereIn('role_has_permissions.permission_id', $permissionIds)
            ->where(fn ($q) => $q->whereNull('roles.partner_id')->orWhereNotIn('roles.partner_id', $hostPartnerIds))
            ->pluck('role_has_permissions.role_id');
        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $otherRoleIds)
            ->delete();

        $strippedUserIds = DB::table('model_has_permissions')
            ->join('users', 'users.id', '=', 'model_has_permissions.model_id')
            ->where('model_has_permissions.model_type', User::class)
            ->whereIn('model_has_permissions.permission_id', $permissionIds)
            ->where(fn ($q) => $q->whereNull('users.partner_id')->orWhereNotIn('users.partner_id', $hostPartnerIds))
            ->pluck('model_has_permissions.model_id');
        DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('model_id', $strippedUserIds)
            ->delete();

        $refresh = array_unique(array_merge($affectedUserIds, $strippedUserIds->all()));
        if ($refresh !== []) {
            DB::table('users')->whereIn('id', $refresh)->update(['is_refresh' => 1]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            "{$permissions->count()} Business Insights permission(s) given to {$developerRoles->count()} Developer role(s) and "
            . count(array_unique($affectedUserIds)) . ' user(s); taken back from '
            . $otherRoleIds->unique()->count() . ' other role(s) and ' . $strippedUserIds->unique()->count() . ' other user(s).'
        );
    }
}
