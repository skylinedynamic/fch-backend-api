<?php

namespace Database\Seeders;

use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Puts the borrower-portal E2E account's password back after the
 * change-password E2E (fch-borrower-react: e2e/topbar/change-password/).
 *
 * The account's password predates the current strength rules, so the portal's
 * own change-password form cannot set it back; this does it directly.
 * Local/testing only.
 *
 *   php artisan db:seed --class=E2EResetBorrowerPasswordSeeder
 */
class E2EResetBorrowerPasswordSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EResetBorrowerPasswordSeeder only runs in local/testing.');
        }

        $email = env('E2E_BORROWER_EMAIL', 'craig-borrower@fourcornerholdings.com');
        $password = env('E2E_BORROWER_PASSWORD', '12345678');

        User::where('email', $email)->firstOrFail()->update(['password' => Hash::make($password)]);

        $this->command?->info("Password reset for {$email}");
    }
}
