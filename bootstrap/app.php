<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Spatie\Permission\Middlewares\RoleMiddleware;
use Spatie\Permission\Middlewares\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
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
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\EnsureTenantAccess::class,
        ]);

        $middleware->append(
            \Illuminate\Http\Middleware\HandleCors::class
        );

        $middleware->alias([
            'require-2fa' => \App\Http\Middleware\RequireTwoFactor::class,
            'check-2fa-status' => \App\Http\Middleware\CheckTwoFactorStatus::class,
            'partner' => \App\Http\Middleware\PartnerMiddleware::class,
            'borrower' => \App\Http\Middleware\BorrowerMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,

            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
