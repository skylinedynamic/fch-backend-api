<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `update sms configuration settings` and gives it to the Admin and
 * Developer roles of the host and of every white-label partner - the partners
 * that run their own branded platform, the same audience as email
 * configuration. Certified partners send through the host's account.
 *
 * Safe to run again. On an existing install:
 *   php artisan db:seed --class=SmsConfigurationPermissionSeeder
 */
class SmsConfigurationPermissionSeeder extends Seeder
{
    private const PERMISSION = 'update sms configuration settings';
    private const MODULE = 'settings';
    private const ROLE_NAMES = ['Admin', 'Developer'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $definition = collect(config('permissions.' . self::MODULE . '.permissions', []))->firstWhere('name', self::PERMISSION);

        $permission = Permission::updateOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'api'],
            [
                'module' => self::MODULE,
                'is_host' => $definition['is_host'] ?? 0,
                'is_certified' => $definition['is_certified'] ?? 0,
            ]
        );

        $partnerIds = Partner::where('is_host', true)
            ->orWhere('partner_type', Partner::PARTNER_TYPE_WHITE_LABEL)
            ->pluck('id');

        // Eager-load `permissions`: givePermissionTo() reads it and lazy
        // loading is disabled in this app.
        $roles = Role::with('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', self::ROLE_NAMES)
            ->where(fn ($query) => $query->whereIn('partner_id', $partnerIds)->orWhereNull('partner_id'))
            ->get();

        $affectedUserIds = [];

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);

            // Some screens read direct user permissions, so mirror the role's
            // grant onto its users - scoped by partner, since role names repeat
            // across tenants.
            $users = User::with('permissions')
                ->where('partner_id', $role->partner_id)
                ->whereHas('roles', fn ($query) => $query->where('id', $role->id))
                ->get();

            foreach ($users as $user) {
                $user->givePermissionTo($permission);
                $affectedUserIds[] = $user->id;
            }
        }

        if ($affectedUserIds !== []) {
            DB::table('users')->whereIn('id', array_unique($affectedUserIds))->update(['is_refresh' => 1]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(__('sms_config.logs.permission_seeded', ['roles' => $roles->count()]));
    }
}
