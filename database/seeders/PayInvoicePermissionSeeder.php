<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Invoice\Invoice;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds "Pay Invoice" (BUG-202): a partner user holding it may pay the
 * partner's own invoices. It goes to every static role - each partner's Admin,
 * the host's Admin and Developer - and to every user holding one, since users
 * here carry their own copy of their role's permissions. Those users are
 * flagged (is_refresh) to reload their permissions on the next request.
 *
 * Other roles get it from Roles & Permissions. Safe to run again. On an
 * existing install:
 *   php artisan db:seed --class=PayInvoicePermissionSeeder
 */
class PayInvoicePermissionSeeder extends Seeder
{
    private const MODULE = 'invoices';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $definition = collect(config('permissions.' . self::MODULE . '.permissions', []))
            ->firstWhere('name', Invoice::PERMISSION_PAY);

        $permission = Permission::updateOrCreate(
            ['name' => Invoice::PERMISSION_PAY, 'guard_name' => 'api'],
            [
                'module' => self::MODULE,
                'is_host' => $definition['is_host'] ?? 0,
                'is_certified' => $definition['is_certified'] ?? 1,
            ]
        );

        // Eager-load `permissions`: givePermissionTo() reads it and lazy
        // loading is disabled in this app.
        $roles = Role::with('permissions')
            ->where('guard_name', 'api')
            ->where('is_static', true)
            ->get();

        $affectedUserIds = [];

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);

            // Scoped by partner: role names repeat across tenants.
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

        $this->command?->info('Pay Invoice given to ' . $roles->count() . ' static role(s) and ' . count(array_unique($affectedUserIds)) . ' user(s).');
    }
}
