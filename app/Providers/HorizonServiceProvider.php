<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // The same rule as viewLogViewer: anyone signed in to the system
        // monitoring app. Horizon is shown inside that app (Queue Jobs menu),
        // so it is locked by that app's login. Job payloads carry borrower
        // data, so tighten this together with viewLogViewer when the
        // monitoring login gets its email allow-list.
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null;
        });
    }
}
