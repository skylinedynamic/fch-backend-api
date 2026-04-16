<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierTaskController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditAccountController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerDocumentController;
use App\Http\Controllers\Api\V1\FCH\Admin\Subscription\SubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditBuilderVendorController;
use App\Http\Controllers\Api\V1\FCH\Admin\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Admin\IntakeReview\ChangeRequest\ChangeRequestController;
// ✅ Import Role & Permission Controllers

/* |-------------------------------------------------------------------------- | Admin API Routes |-------------------------------------------------------------------------- | All admin API endpoints are loaded here. | Protected by Passport (auth:api) and Admin middleware. | We also include set.current.business for business-wise data scoping. |-------------------------------------------------------------------------- */

Route::prefix('v1/admin')
    ->middleware(['auth:api', 'admin'])
    ->group(function () {

        // 🧭 Partner routes
        Route::post('partners/fetch-partners', [PartnerController::class, 'index']);
        Route::post('partners', [PartnerController::class, 'store']);
        Route::put('partners/{id}', [PartnerController::class, 'update']);
        Route::get('partners-details/{id}', [PartnerController::class, 'details']);
        Route::post('partners/fetch-borrowers', [PartnerController::class, 'getBorrowers']);
        Route::post('partners/multiple-delete', [PartnerController::class, 'partnerDelete']);
        Route::post('partners/check-deletable', [PartnerController::class, 'checkDeletable']);

        Route::post('partners-profile-states-details-count/{id}', [PartnerController::class, 'profileStatsCount']);
        Route::get('partners/next-id', [PartnerController::class, 'getNextId']);

        //  Partner Documents
        Route::post('partners/fetch-all-documents', [PartnerDocumentController::class, 'index']);
        Route::post('partners/documents', [PartnerDocumentController::class, 'store']);
        Route::post('partners/documents/{id}', [PartnerDocumentController::class, 'update']); // Using POST for file upload update
        Route::get('partners/documents/download/{id}', [PartnerDocumentController::class, 'download']);
        Route::post('partners/documents/delete/{id}', [PartnerDocumentController::class, 'delete']);

        // 💳 Subscriptions
        Route::post('partners/fetch-all-subscriptions', [SubscriptionController::class, 'fetchAll']);
        Route::post('partners/fetch-subscriptions/{partnerId}', [SubscriptionController::class, 'index']);

        Route::post('partners/subscription', [SubscriptionController::class, 'store']);
        Route::post('partner-subscriptions/{partnerId}', [SubscriptionController::class, 'details']);

        Route::post('partners/subscriptions-stats', [SubscriptionController::class, 'stats']);

        // 📅 Demo requests
        Route::get('demo-requests', [DemoRequestController::class, 'index']);
        Route::post('demo-requests/store', [DemoRequestController::class, 'store']);
        // Route::post('demo-requests/update', [DemoRequestController::class, 'update']);
    
        // 🏆 Credit Builder
        Route::prefix('credit-builder')->group(
            function () {
            // Tiers
            Route::post('tiers/fetch-tiers', [TierController::class, 'index']);
            Route::post('tiers', [TierController::class, 'store']);
            Route::get('tiers/{id}', [TierController::class, 'show']);
            Route::put('tiers/{id}', [TierController::class, 'update']);
            Route::post('tiers-multiple-delete', [TierController::class, 'multipleDelete']);
            Route::get('tier-related-api/{tier_id}', [TierController::class, 'getTierRelatedApi']);

            // Tier Tasks
            Route::post('tier-tasks/fetch-tasks', [TierTaskController::class, 'index']);
            Route::post('tier-tasks', [TierTaskController::class, 'store']);
            Route::get('tier-tasks/{id}', [TierTaskController::class, 'show']);
            Route::put('tier-tasks/{id}', [TierTaskController::class, 'update']);
            Route::post('tier-tasks-multiple-delete', [TierTaskController::class, 'multipleDelete']);

            // Vendors
            Route::post('vendors/fetch-vendors', [CreditBuilderVendorController::class, 'index']);
            Route::post('vendors', [CreditBuilderVendorController::class, 'store']);
            Route::get('vendors/{id}', [CreditBuilderVendorController::class, 'show']);
            Route::put('vendors/{id}', [CreditBuilderVendorController::class, 'update']);
            Route::delete('vendors/{id}', [CreditBuilderVendorController::class, 'destroy']);

            // Credit Accounts
            Route::post('credit-accounts/fetch-accounts', [CreditAccountController::class, 'index']);
            Route::post('credit-accounts', [CreditAccountController::class, 'store']);
            Route::get('credit-accounts/{id}', [CreditAccountController::class, 'show']);
            Route::put('credit-accounts/{id}', [CreditAccountController::class, 'update']);
            Route::patch('credit-accounts/{id}/status', [CreditAccountController::class, 'updateStatus']);
            Route::delete('credit-accounts/{id}', [CreditAccountController::class, 'destroy']);
        }
        );


        Route::prefix('funding-application')->group(
            function () {
                Route::post('save-commission', [CommissionController::class, 'saveCommission']);
            }
        );

        // Change Requests (Borrower Field Update Requests)
        Route::prefix('change-requests')->group(function () {
            Route::post('/', [ChangeRequestController::class, 'index']);
            Route::patch('/{id}', [ChangeRequestController::class, 'updateStatus']);
        });
    });
