<?php

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerDocumentController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerSubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierController;
use Illuminate\Support\Facades\Route;

// ✅ Import Role & Permission Controllers

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
| All admin API endpoints are loaded here.
| Protected by Passport (auth:api) and Admin middleware.
| We also include set.current.business for business-wise data scoping.
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->middleware(['auth:api', 'admin'])
    ->group(function () {

        // 🧭 Partner routes
        Route::post('partners/fetch-partners', [PartnerController::class, 'index']);
        Route::post('partners/get-all-users', [PartnerController::class, 'getAllUsers']);
        Route::post('partners', [PartnerController::class, 'store']);
        Route::put('partners/{id}', [PartnerController::class, 'update']);
        Route::get('partners-details/{id}', [PartnerController::class, 'details']);
        Route::post('partners/fetch-borrowers', [PartnerController::class, 'getBorrowers']);
        Route::post('partners/multiple-delete', [PartnerController::class, 'partnerDelete']);
        Route::post('partners-stats', [PartnerController::class, 'stats']);
        Route::post('partners-profile-states-details-count/{id}', [PartnerController::class, 'profileStatsCount']);

        //  Partner Documents
        Route::post('partners/fetch-all-documents', [PartnerDocumentController::class, 'index']);
        Route::post('partners/documents', [PartnerDocumentController::class, 'store']);
        Route::post('partners/documents/{id}', [PartnerDocumentController::class, 'update']); // Using POST for file upload update
        Route::get('partners/documents/download/{id}', [PartnerDocumentController::class, 'download']);
        Route::post('partners/documents/delete/{id}', [PartnerDocumentController::class, 'delete']);

        // 💳 Partner Subscriptions
        Route::post('partners/subscription', [PartnerSubscriptionController::class, 'store']);
        Route::post('partner-subscriptions/{partnerId}', [PartnerSubscriptionController::class, 'details']);

        // 📅 Demo requests
        Route::get('demo-requests', [DemoRequestController::class, 'index']);
        Route::post('demo-requests/store', [DemoRequestController::class, 'store']);
        // Route::post('demo-requests/update', [DemoRequestController::class, 'update']);
        
        // 🏆 Credit Builder
        Route::prefix('credit-builder')->group(function () {
            // Tiers
            Route::post('tiers/fetch-tiers', [TierController::class, 'index']);
            Route::post('tiers', [TierController::class, 'store']);
            Route::get('tiers/{id}', [TierController::class, 'show']);
            Route::put('tiers/{id}', [TierController::class, 'update']);
            Route::post('tiers-multiple-delete', [TierController::class, 'multipleDelete']);
        });
    });
