<?php

namespace App\Providers;

use App\Helpers\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
            RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->email;
            $key = 'login:' . $email . $request->ip();
            $time = 5*60; // 5 minutes
            $maxAttempts = 5;

            return Limit::perMinute($maxAttempts)           // 5 attempts per minute
                ->by($key)                        // key by email + ip
                ->response(function () use ($time, $key, $maxAttempts) {
                    $attempts = RateLimiter::attempts($key);
                    $remainingAttempts = max(0, $maxAttempts - $attempts);
                    
                    return ApiResponse::tooManyRequests(trans_choice('auth.too_many_login_attempts', $time / 60, ['time' => $time / 60]), [
                        'retry_after' => $time,
                        'time' => now()->toISOString(),
                        'remaining_attempts' => $remainingAttempts,
                        'max_attempts' => $maxAttempts
                    ]);
                });
        });

        // Support assistant chat: each OpenAI call has a real cost and
        // latency, so this is capped per authenticated user (falling back to
        // IP for the rare unauthenticated hit) rather than left unbounded.
        RateLimiter::for('assistant', function (Request $request) {
            $key = optional($request->user())->getKey() ?: $request->ip();

            return Limit::perMinute(20)->by((string) $key);
        });
    }
}