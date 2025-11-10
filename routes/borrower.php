<?php

use App\Http\Controllers\Api\V1\Borrower\Auth\AuthController;
use App\Http\Controllers\Api\V1\Borrower\Document\DocumentController;
use App\Http\Controllers\Api\V1\Borrower\PreQualification\PreQualificationController;
use App\Http\Controllers\Api\V1\Borrower\Profile\BusinessInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\FinancialInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\PersonalInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\ProfileCompletionController;
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

        Route::post('/logout', [AuthController::class, 'logout']);
        
        // Pre-Qualification
        Route::get('/pre-qualification', [PreQualificationController::class, 'index']);
        Route::post('/pre-qualification', [PreQualificationController::class, 'storeOrUpdate']);
        
        Route::group(['prefix' => 'profile',], function () {
            Route::get('/personal-info', [PersonalInfoController::class, 'index']);
            Route::put('/personal-info', [PersonalInfoController::class, 'update']);

            Route::get('/business-info', [BusinessInfoController::class, 'index']);
            Route::put('/business-info', [BusinessInfoController::class, 'update']);

            Route::get('/financial-info', [FinancialInfoController::class, 'index']);
            Route::put('/financial-info', [FinancialInfoController::class, 'update']);
            
            Route::get('/completion', [ProfileCompletionController::class, 'check']);

        });

         Route::prefix('documents')->group(function () {
                Route::get('/', [DocumentController::class, 'index']);
                Route::post('/upload', [DocumentController::class, 'store']);
            });

    });
});