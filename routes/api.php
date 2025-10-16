<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\V1\Auth\AuthController as V1AuthController;

// V1 auth routes
Route::prefix('v1/auth')->group(function () {

    Route::post('/login', [V1AuthController::class, 'login']);

    Route::post('/forgot', [V1AuthController::class, 'forgot']);
        
    Route::post('/reset', [V1AuthController::class, 'reset']);
});


