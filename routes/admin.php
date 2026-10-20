<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierTaskController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditAccountController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerDocumentController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditBuilderVendorController;
use App\Http\Controllers\Api\V1\FCH\Admin\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Admin\IntakeReview\ChangeRequest\ChangeRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\Resource\ResourceController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TaskVerificationController;
use App\Http\Controllers\Api\V1\FCH\Admin\CMS\BlogController;
use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SeoController;
use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SiteSettingsController;
use App\Http\Controllers\Api\V1\FCH\Admin\Configuration\AppConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Admin\ScoreConfiguration\ScoreConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditProfileRequest\CreditProfileRequestAdminController;
use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingGroupController;
use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingSequencingController;
use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingTypeController;
use App\Http\Controllers\Api\V1\FCH\Admin\Lender\LenderController;
use App\Http\Controllers\Api\V1\FCH\Admin\Lender\LenderProductCategoryController;
use App\Http\Controllers\Api\V1\FCH\Admin\PaymentMethod\PaymentMethodController;
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

        // Archiving is an edit to the partner record, not a deletion, so it sits
        // behind the same permission as any other partner edit.
        Route::post('partners/archive', [PartnerController::class, 'archive'])
            ->middleware('permission:update partners');
        Route::post('partners/unarchive', [PartnerController::class, 'unarchive'])
            ->middleware('permission:update partners');
        Route::get('partners/tenant-config/{id}', [PartnerController::class, 'getTenantConfig']);
        Route::post('partners/tenant-config/{id}', [PartnerController::class, 'updateTenantConfig']);

        Route::post('partners-profile-states-details-count/{id}', [PartnerController::class, 'profileStatsCount']);
        Route::get('partners/next-id', [PartnerController::class, 'getNextId']);

        //  Partner Documents
        Route::post('partners/fetch-all-documents', [PartnerDocumentController::class, 'index']);
        Route::post('partners/documents', [PartnerDocumentController::class, 'store']);
        Route::get('partners/documents/download/{id}', [PartnerDocumentController::class, 'download']);
        Route::post('partners/documents/delete/{id}', [PartnerDocumentController::class, 'delete']);

        // 💰 Plan & Fee (Admin/Host Partner only)
        Route::post('plan-fee/fetch-subscription-plans', [\App\Http\Controllers\Api\V1\FCH\Admin\PlanFee\PlanFeeController::class, 'index']);
        Route::put('plan-fee/update-subscription-plan/{id}', [\App\Http\Controllers\Api\V1\FCH\Admin\PlanFee\PlanFeeController::class, 'update']);

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
            Route::post('tiers/borrowers/tier-wise-tasklist', [TierController::class, 'getBorrowerTierTaskList']);
            Route::post('tiers/borrowers/progress', [TierController::class, 'getBorrowerProgress']);

            // Tier Tasks
            Route::post('tier-tasks/fetch-tasks', [TierTaskController::class, 'index']);
            Route::post('tier-tasks', [TierTaskController::class, 'store']);
            Route::get('tier-tasks/{id}', [TierTaskController::class, 'show']);
            Route::put('tier-tasks/{id}', [TierTaskController::class, 'update']);
            Route::post('tier-tasks-multiple-delete', [TierTaskController::class, 'multipleDelete']);
            Route::post('tasks/update-status', [TaskVerificationController::class, 'updateStatus']);

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

        // 💰 Funding Groups (CRUD)
        Route::prefix('funding-groups')->group(function () {
            Route::get('/', [FundingGroupController::class, 'index']);
            Route::post('/', [FundingGroupController::class, 'store']);
            Route::put('{id}', [FundingGroupController::class, 'update']);
            Route::delete('{id}', [FundingGroupController::class, 'destroy']);
        });

        // 🏦 Lenders (CRUD)
        Route::prefix('lenders')->group(function () {
            // Product Categories
            Route::prefix('product-categories')->group(function () {
                Route::get('/', [LenderProductCategoryController::class, 'index']);
                Route::post('/', [LenderProductCategoryController::class, 'store']);
                Route::put('{id}', [LenderProductCategoryController::class, 'update']);
                Route::delete('{id}', [LenderProductCategoryController::class, 'destroy']);
            });

            Route::get('/', [LenderController::class, 'index']);
            Route::get('get/all', [LenderController::class, 'getAll']);
            Route::post('multiple-delete', [LenderController::class, 'multipleDelete']);
            Route::post('check-deletable', [LenderController::class, 'checkDeletable']);
            Route::post('/', [LenderController::class, 'store']);
            Route::get('{id}', [LenderController::class, 'show']);
            Route::put('{id}', [LenderController::class, 'update']);
            Route::delete('{id}', [LenderController::class, 'destroy']);
        });

        // 💰 Funding Types (CRUD)
        Route::prefix('funding-types')->group(function () {
            Route::get('/', [FundingTypeController::class, 'index']);
            Route::post('/', [FundingTypeController::class, 'store']);
            Route::put('{id}', [FundingTypeController::class, 'update']);
            Route::delete('{id}', [FundingTypeController::class, 'destroy']);
        });


        // 💰 Funding Sequencings
        Route::prefix('funding-sequencings')->group(function () {
            Route::post('fetch', [FundingSequencingController::class, 'index']);
            Route::post('multiple-delete', [FundingSequencingController::class, 'multipleDelete']);
            Route::post('check-deletable', [FundingSequencingController::class, 'checkDeletable']);
            Route::get('grouped', [FundingSequencingController::class, 'grouped']);
            Route::post('/', [FundingSequencingController::class, 'store']);
            Route::post('{id}/visibilities/fetch', [FundingSequencingController::class, 'fetchVisibilities']);
            Route::get('{id}', [FundingSequencingController::class, 'show']);
            Route::put('{id}', [FundingSequencingController::class, 'update']);
            Route::put('{id}/display', [FundingSequencingController::class, 'updateDisplay']);
            Route::put('{id}/visibility', [FundingSequencingController::class, 'updateVisibility']);
            Route::post('{id}/lender/{lenderId}/visibility', [FundingSequencingController::class, 'updateSingleLenderVisibility']);
            Route::post('{id}/lender/{lenderId}/category/{categoryId}/visibility', [FundingSequencingController::class, 'updateSingleCategoryVisibility']);
            Route::delete('{id}/visibilities', [FundingSequencingController::class, 'deleteVisibilities']);
            Route::patch('{id}/status', [FundingSequencingController::class, 'updateStatus']);
            Route::delete('{id}', [FundingSequencingController::class, 'destroy']);
        });


        Route::prefix('funding-application')->group(
            function () {
                Route::post('save-commission', [CommissionController::class, 'saveCommission']);
            }
        );

        Route::prefix('commission')->group(function () {
            Route::post('{id}/payout', [CommissionController::class, 'processPayout']);
        });

        // Change Requests (Borrower Field Update Requests)
        Route::prefix('change-requests')->group(function () {
            Route::post('/', [ChangeRequestController::class, 'index']);
            Route::patch('/{id}', [ChangeRequestController::class, 'updateStatus']);
        });

        // 📚 Resources
        Route::prefix('resources')->group(function () {
            Route::get('/next-id', [ResourceController::class, 'getNextId']);
            Route::get('/type/{resource_type}', [ResourceController::class, 'getByType']);
            Route::post('/', [ResourceController::class, 'store']);
            Route::get('/{id}', [ResourceController::class, 'show']);
            Route::match(['post', 'put'], '/{id}', [ResourceController::class, 'update']);
            Route::delete('/{id}', [ResourceController::class, 'destroy']);
        });

        // 📝 CMS Routes
        Route::prefix('cms')->group(function () {
            // CMS
            Route::get('blogs', [BlogController::class, 'index']);
            Route::post('blogs/fetch', [BlogController::class, 'index']);
            Route::post('blogs', [BlogController::class, 'store']);
            Route::get('blogs/{id}', [BlogController::class, 'show']);
            Route::post('blogs/{id}', [BlogController::class, 'update']);
            Route::post('blogs-multiple-delete', [BlogController::class, 'destroy']);

            Route::get('blog-categories', [BlogController::class, 'categories']);
            Route::post('blog-categories', [BlogController::class, 'storeCategory']);
            Route::post('blog-categories/{id}', [BlogController::class, 'updateCategory']);
            Route::post('blog-categories-multiple-delete', [BlogController::class, 'destroyCategory']);

            Route::get('blog-tags', [BlogController::class, 'tags']);
            Route::post('blog-tags', [BlogController::class, 'storeTag']);
            Route::post('blog-tags/{id}', [BlogController::class, 'updateTag']);
            Route::post('blog-tags-multiple-delete', [BlogController::class, 'destroyTag']);

            // Site Settings
            Route::get('site-settings', [SiteSettingsController::class, 'index']);
            Route::post('site-settings', [SiteSettingsController::class, 'update']);



            // SEO
            Route::post('seo-settings-multiple-delete', [SeoController::class, 'destroy']);
            Route::post('seo-settings', [SeoController::class, 'index']);        // list (POST)
            Route::get('seo-settings', [SeoController::class, 'getAll']);        // list (GET)
            Route::post('seo-settings/store', [SeoController::class, 'store']); // create new (must be before /{id})
            Route::get('seo-settings/{id}', [SeoController::class, 'show']);     // get one
            Route::post('seo-settings/{id}', [SeoController::class, 'update']); // update
        });

        // 📊 Score Configurations
        Route::prefix('app-configuration/score')->group(function () {
            Route::get('/', [ScoreConfigurationController::class, 'index']);
            Route::put('/', [ScoreConfigurationController::class, 'update']);
        });

        // Credit Profile Requests
        Route::prefix('credit-profile-requests')->group(function () {
            Route::post('/', [CreditProfileRequestAdminController::class, 'index']);
            Route::get('/{id}', [CreditProfileRequestAdminController::class, 'show']);
        });

        // Payment Provider Management Routes
        Route::prefix('payment-methods')->group(function () {
            Route::get('/', [PaymentMethodController::class, 'index']);
            Route::post('{id}', [PaymentMethodController::class, 'update']);
        });

    });
