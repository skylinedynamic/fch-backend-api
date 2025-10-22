<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin API routes for your application.
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1/admin')->middleware(['auth:api', 'admin'])->group(function () {
    // Admin-specific routes will be added here
    // Example:
    // Route::get('/dashboard', [AdminController::class, 'dashboard']);
    // Route::get('/users', [AdminUserController::class, 'index']);
    // Route::post('/users', [AdminUserController::class, 'store']);
});