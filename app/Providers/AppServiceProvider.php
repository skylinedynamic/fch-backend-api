<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\URL;
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
use Spatie\Health\Checks\Checks\ScheduleCheck;
use App\HealthChecks\SchedulerRanCheck;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Score configuration singleton — loads all score_configurations rows once
        // per request and serves every tier/modifier lookup from memory.
        $this->app->singleton(
            \App\Services\Borrower\CapitalAccess\ScoreConfigurationCache::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
        
        Model::preventLazyLoading(!app()->isProduction());

        $checks = [
            DatabaseCheck::new (),
            DebugModeCheck::new ()->unless(app()->isLocal()),
            EnvironmentCheck::new ()->expectEnvironment(app()->environment()),
            PingCheck::new ()->name('Local API Gateway')->url(config('app.url'))->unless(app()->isLocal()),
            PingCheck::new ()->name('Network (Google)')->url('https://google.com'),
            SchedulerRanCheck::new(),
        ];

        // Optional external heartbeat check (requires SCHEDULE_HEARTBEAT_URL env).
        if (config('health.schedule.heartbeat_url')) {
            $checks[] = ScheduleCheck::new();
        }

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

            return $user;
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
