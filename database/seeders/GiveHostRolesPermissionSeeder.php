<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives one permission from config/permissions.php to roles 1 and 2 - the
 * host's Developer and Admin - and to the users holding them. Safe to run again.
 *
 * Check that roles 1 and 2 are the host's Developer and Admin on the target
 * database before running a subclass there.
 */
abstract class GiveHostRolesPermissionSeeder extends Seeder
{
    /**
     * The permission as defined in config/permissions.php.
     *
     * @return array{name: string, is_host?: int, is_certified?: int}
     */
    abstract protected function definition(): array;

    /** The host's Developer and Admin roles. */
    private const ROLE_IDS = [1, 2];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $definition = $this->definition();

        $permission = Permission::updateOrCreate(
            ['name' => $definition['name'], 'guard_name' => 'api'],
            [
                'module' => $this->moduleOf($definition['name']),
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

    /** The module the permission sits under in config/permissions.php. */
    private function moduleOf(string $name): string
    {
        foreach (config('permissions') as $module => $group) {
            if (collect($group['permissions'] ?? [])->contains('name', $name)) {
                return $module;
            }
        }

        throw new \RuntimeException("'{$name}' is not in config/permissions.php.");
    }
}
