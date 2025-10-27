<?php

use App\Http\Controllers\Api\V1\Borrower\Auth\AuthController;
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

Route::prefix('v1/borrower')->group(function () {
    // Public routes (no authentication required)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    
    // Protected routes (authentication required)
    Route::middleware(['auth:api', 'borrower'])->group(function () {
        // Add protected borrower routes here
    });
});