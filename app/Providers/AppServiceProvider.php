<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Spatie\Health\Facades\Health;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\PingCheck;

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
        Model::preventLazyLoading(!app()->isProduction());

        $checks = [
            DatabaseCheck::new (),
            DebugModeCheck::new ()->unless(app()->isLocal()),
            EnvironmentCheck::new ()->expectEnvironment(app()->environment()),
            PingCheck::new ()->name('Local API Gateway')->url(config('app.url'))->unless(app()->isLocal()),
            PingCheck::new ()->name('Network (Google)')->url('https://google.com'),
        ];

        if (PHP_OS_FAMILY !== 'Windows') {
            $checks[] = UsedDiskSpaceCheck::new ()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(90);
        }

        Health::checks($checks);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
            );
        });

        // Register borrower API module
        Scramble::registerApi('borrower', [
            'api_path' => 'api/v1/borrower',
        ]);

        Scramble::registerApi('borrower')
            ->routes(function (Route $route) {
            return Str::startsWith($route->uri, 'api/v1/borrower')
            || Str::startsWith($route->uri, 'api/v1/document')
            || Str::startsWith($route->uri, 'api/v1/filemanager')
            || Str::startsWith($route->uri, 'api/v1/2fa');
        });


        // Register combined admin docs (admin + partner) under one URL
        Scramble::registerApi('admin')
            ->routes(function (Route $route) {
            return Str::startsWith($route->uri, 'api/v1/admin')
            || Str::startsWith($route->uri, 'api/v1/partner')
            || Str::startsWith($route->uri, 'api/v1/auth')
            || Str::startsWith($route->uri, 'api/v1/notifications')
            || Str::startsWith($route->uri, 'api/v1/common')
            || Str::startsWith($route->uri, 'api/v1/filemanager')
            || Str::startsWith($route->uri, 'api/v1/2fa');
        });
        Gate::define('viewApiDocs', function (?User $user) {
            // Allowing public access on all environments for now
            return true;
        });

        Gate::define('viewLogViewer', function (?User $user) {
            if (app()->isLocal()) {
                return true;
            }

            if (!$user) {
                return false;
            }

            $allowedEmails = array_filter(array_map('trim', explode(',', (string)env('LOG_VIEWER_ALLOWED_EMAILS', ''))));
            return in_array($user->email, $allowedEmails, true);
        });
    }
}
