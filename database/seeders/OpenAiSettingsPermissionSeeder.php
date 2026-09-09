<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class OpenAiSettingsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create or update the permission. The OpenAI settings screen is
        //    host-only, so is_host is 1 — matching config/permissions.php.
        $permission = Permission::updateOrCreate(
            ['name' => 'update openai settings', 'guard_name' => 'api'],
            [
                'module' => 'settings',
                'is_host' => 1,
                'is_certified' => 0,
            ]
        );

        $this->command?->info('OpenAI settings permission created/updated in permissions table.');

        // 2. Resolve the host partner (partners.is_host = 1) rather than assuming
        //    role ids, so the seeder behaves the same on every environment.
        $host = Partner::host();

        if (! $host) {
            $this->command?->error('No host partner found (partners.is_host = 1). Nothing assigned.');

            return;
        }

        $this->command?->info("Host partner: #{$host->id}");

        // 3. Grant to every role belonging to the host partner. Nothing outside
        //    the host partner is touched, so tenant roles never receive it.
        //    Eager load: lazy loading is disabled in this application.
        $hostRoles = Role::with('permissions')->where('partner_id', $host->id)->get();

        foreach ($hostRoles as $role) {
            $role->givePermissionTo($permission);
        }

        $this->command?->info("Granted to {$hostRoles->count()} host role(s): " . $hostRoles->pluck('name')->implode(', '));

        // 4. Grant directly to every user of the host partner as well, so access
        //    does not depend on which host role a user happens to hold.
        $hostUsers = User::with('permissions')->where('partner_id', $host->id)->get();

        foreach ($hostUsers as $user) {
            if (method_exists($user, 'givePermissionTo')) {
                $user->givePermissionTo($permission);
            }
        }

        $this->command?->info("Granted to {$hostUsers->count()} host user(s).");

        // Force all users to refresh their session/tokens
        DB::table('users')->update(['is_refresh' => 1]);

        // Clear cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('OpenAiSettingsPermissionSeeder completed successfully!');
    }
}
