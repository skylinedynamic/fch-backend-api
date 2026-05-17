<?php

use Illuminate\Support\Facades\Route;
use Dedoc\Scramble\Scramble;
use App\Http\Controllers\DomainResolverController;

// Internal resolver routes — no session, no cookies
Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \App\Http\Middleware\VerifyCsrfToken::class,
])->group(function () {
    Route::get('/resolve-custom-domain', [DomainResolverController::class, 'resolve'])->name('resolver.resolve');
    Route::get('/caddy/domain-check', [DomainResolverController::class, 'caddyCheck']);
});

Route::get('/', function () {
    return redirect('/docs/admin');
});

Route::get('/testing', function () {
    return view('recaptcha');
});

// Register borrower API documentation routes
Scramble::registerUiRoute('docs/borrower', api: 'borrower');
Scramble::registerJsonSpecificationRoute('docs/borrower/api.json', api: 'borrower');

// Register combined admin documentation (admin + partner) under one URL
Scramble::registerUiRoute('docs/admin', api: 'admin');
Scramble::registerJsonSpecificationRoute('docs/admin/api.json', api: 'admin');