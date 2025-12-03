<?php

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerDocumentController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerSubscriptionController;
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
        Route::get('partners/stats', [PartnerController::class, 'stats']);
        Route::get('partners/profile-states-details-count/{id}', [PartnerController::class, 'profileStatsCount']);

        //  Partner Documents
        Route::post('partners/fetch-all-documents', [PartnerDocumentController::class, 'index']);
        Route::post('partners/documents', [PartnerDocumentController::class, 'store']);
        Route::post('partners/documents/{id}', [PartnerDocumentController::class, 'update']); // Using POST for file upload update
        Route::get('partners/documents/download/{id}', [PartnerDocumentController::class, 'download']);
        Route::post('partners/documents/delete/{id}', [PartnerDocumentController::class, 'delete']);

        // 💳 Partner Subscriptions
        Route::post('partners/subscription', [PartnerSubscriptionController::class, 'store']);
        Route::get('partners/subscription/{partnerId}', [PartnerSubscriptionController::class, 'show']);

        // 📅 Demo requests
        Route::get('demo-requests', [DemoRequestController::class, 'index']);
        Route::post('demo-requests/store', [DemoRequestController::class, 'store']);
        // Route::post('demo-requests/update', [DemoRequestController::class, 'update']);
    });
