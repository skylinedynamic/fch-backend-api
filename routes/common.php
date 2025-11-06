<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController as V1TwoFaController;
use App\Http\Controllers\Api\V1\Global\Notification\NotificationController as V1NotificationController;
use App\Http\Controllers\Api\V1\Global\Document\DocumentController as V1DocumentController;

Route::prefix('v1')->group(function () {
    // V1 auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [V1AuthController::class, 'login']);
        Route::post('/login/2fa/confirm', [V1TwoFaController::class, 'login_confirm']);
        Route::post('/forgot', [V1AuthController::class, 'forgot']);
        Route::post('/reset', [V1AuthController::class, 'reset']);
    });

    // V1 2FA routes
    Route::prefix('2fa')->middleware('auth:api')->group(function () {
        Route::post('/setup', [V1TwoFaController::class, 'setup']);
        Route::post('/verify', [V1TwoFaController::class, 'verify']);
        Route::post('/disable', [V1TwoFaController::class, 'disable']);
        Route::post('/resend', [V1TwoFaController::class, 'resend']);
        Route::get('/status', [V1TwoFaController::class, 'status']);
        Route::post('/recovery-codes/regenerate', [V1TwoFaController::class, 'regenerateRecoveryCodes']);
    });

    // V1 Notification routes
    Route::prefix('notifications')->middleware('auth:api')->group(function () {
        Route::post('/test-email', [V1NotificationController::class, 'sendTestEmail']);
    });

    // V1 Document routes
    Route::prefix('documents')->middleware('auth:api')->group(function () {
        Route::post('/upload', [V1DocumentController::class, 'store']);
    });

});