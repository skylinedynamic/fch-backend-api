<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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
