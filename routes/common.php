<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Common\Auth\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\Common\TwoFaAuth\TwoFaController as V1TwoFaController;

Route::prefix('v1')->group(function () {
    // V1 auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [V1AuthController::class, 'login']);
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

});