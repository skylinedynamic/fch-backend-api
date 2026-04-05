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
        Gate::define('viewApiDocs', function (User $user) {
            $allowed = array_filter(array_map('trim', explode(',', (string) env('SCRAMBLE_ALLOWED_EMAILS', ''))));
            if (empty($allowed)) {
                // If not configured, allow in local only
                return app()->isLocal();
            }
            return in_array($user->email, $allowed, true);
        });

        Gate::define('viewLogViewer', function (?User $user) {
            if (app()->isLocal()) {
                return true;
            }

            if (!$user) {
                return false;
            }

            $allowedEmails = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_ALLOWED_EMAILS', ''))));
            return in_array($user->email, $allowedEmails, true);
        });
    }
}
