<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Borrower API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register borrower API routes for your application.
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1/borrower')->middleware(['auth:api', 'borrower'])->group(function () {
    // Borrower-specific routes will be added here
    // Example:
    // Route::get('/profile', [BorrowerController::class, 'profile']);
    // Route::put('/profile', [BorrowerController::class, 'updateProfile']);
    // Route::get('/loans', [BorrowerLoanController::class, 'index']);
    // Route::post('/loan-applications', [BorrowerLoanController::class, 'apply']);
});