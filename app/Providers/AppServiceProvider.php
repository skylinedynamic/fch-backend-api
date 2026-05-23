<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        URL::forceScheme('https');

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
        Gate::define('viewApiDocs', function (?User $user) {
            // Allowing public access on all environments for now
            return true;
        });

        // 📧 Global BCC Listener: Automatically adds BCCs from the partner's active configuration
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            $bcc = config('mail.bcc');
            if ($bcc) {
                $bccEmails = array_map('trim', explode(',', $bcc));
                foreach ($bccEmails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $event->message->addBcc($email);
                    }
                }
            }
        });
    }
}
