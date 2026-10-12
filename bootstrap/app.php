<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: [
            __DIR__ . '/../routes/common.php',
            __DIR__ . '/../routes/admin.php',
            __DIR__ . '/../routes/borrower.php',
            __DIR__ . '/../routes/filemanager.php',
            __DIR__ . '/../routes/website.php',
        ],

        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepend CORS to the very top of the global stack
        $middleware->prepend(
            \Illuminate\Http\Middleware\HandleCors::class
        );

        // Trust the reverse proxy / load balancer in front of the API.
        //
        // Without this, request()->ip() returns the socket peer - the proxy
        // itself - so every embedded form submission recorded 127.0.0.1 and the
        // IP column carried no fraud, geo, velocity or audit value at all.
        //
        // The default trusts only loopback and the RFC1918 private ranges, which
        // is where a same-host or same-VPC proxy sits. Public clients are NOT
        // trusted, so a bot cannot forge X-Forwarded-For and have Laravel
        // believe it. Deployments whose edge sits on a public address set
        // TRUSTED_PROXIES to that address or CIDR; '*' is available but means
        // the header is accepted from anyone, so use it only when the app is
        // genuinely unreachable except through the edge.
        $middleware->trustProxies(
            at: array_map('trim', explode(',', (string) env(
                'TRUSTED_PROXIES',
                '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Remember each user's browser timezone for work done outside a request.
        $middleware->api(append: [
            \App\Http\Middleware\RememberUserTimezone::class,
        ]);

        $middleware->alias([
            'require-2fa' => \App\Http\Middleware\RequireTwoFactor::class,
            'check-2fa-status' => \App\Http\Middleware\CheckTwoFactorStatus::class,
            'partner' => \App\Http\Middleware\PartnerMiddleware::class,
            'borrower' => \App\Http\Middleware\BorrowerMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'partner-portal' => \App\Http\Middleware\RejectBorrowersFromPartnerPortal::class,
            'host-only' => \App\Http\Middleware\HostOnly::class,

            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('GLOBAL_EXCEPTION: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1000)
            ]);
        });
    })
    ->create();
