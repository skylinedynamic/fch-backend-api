<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', function (User $user) {
            $allowed = array_filter(array_map('trim', explode(',', (string) env('SCRAMBLE_ALLOWED_EMAILS', ''))));
            if (empty($allowed)) {
                // If not configured, allow in local only
                return app()->isLocal();
            }
            return in_array($user->email, $allowed, true);
        });
    }
}
