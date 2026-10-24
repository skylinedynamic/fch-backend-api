<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `manage partner configuration` (Partner profile › Configuration: the
 * host editing a partner's Email, SMS, Branding and Payment settings) and gives
 * it to roles 1 and 2 - the host's Developer and Admin - and to their users.
 *
 * Safe to run again. On an existing install:
 *   php artisan db:seed --class=PartnerConfigurationPermissionSeeder
 *
 * Check that roles 1 and 2 are the host's Developer and Admin on the target
 * database before running it there.
 */
class PartnerConfigurationPermissionSeeder extends Seeder
{
    private const MODULE = 'partners';

    /** Its key under the module in config/permissions.php. */
    private const CONFIG_KEY = 'manage_configuration';

    /** The host's Developer and Admin roles. */
    private const ROLE_IDS = [1, 2];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The permission as defined in config/permissions.php › partners.
        $definition = config('permissions.' . self::MODULE . '.permissions.' . self::CONFIG_KEY);

        $permission = Permission::updateOrCreate(
            ['name' => $definition['name'], 'guard_name' => 'api'],
            [
                'module' => self::MODULE,
                'is_host' => $definition['is_host'] ?? 1,
                'is_certified' => $definition['is_certified'] ?? 0,
            ]
        );

        // Eager-load `permissions`: givePermissionTo() reads it and lazy
        // loading is disabled in this app.
        $roles = Role::with('permissions')
            ->where('guard_name', 'api')
            ->whereIn('id', self::ROLE_IDS)
            ->get();

        $affectedUserIds = [];

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);

            // Some screens read direct user permissions, so mirror the role's
            // grant onto its users.
            $users = User::with('permissions')
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

        $this->command?->info("'" . $permission->name . "' given to {$roles->count()} role(s) and " . count(array_unique($affectedUserIds)) . ' user(s).');
    }
}
