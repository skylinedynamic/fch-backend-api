<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Borrower\Auth\AuthController;
use App\Http\Controllers\Api\V1\Borrower\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Borrower\Common\BorrowerController;
use App\Http\Controllers\Api\V1\Borrower\Invoice\InvoiceController;
use App\Http\Controllers\Api\V1\Borrower\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Borrower\Auth\AuthProfileController;
use App\Http\Controllers\Api\V1\Borrower\Document\DocumentController;
use App\Http\Controllers\Api\V1\Borrower\Setting\SettingController;
use App\Http\Controllers\Api\V1\GlobalPayment\GlobalPaymentController;
use App\Http\Controllers\Api\V1\Borrower\Profile\BusinessInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\PersonalInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\FinancialInfoController;
use App\Http\Controllers\Api\V1\Borrower\Auth\BorrowerUserDetailController;
use App\Http\Controllers\Api\V1\Borrower\Profile\ProfileCompletionController;
use App\Http\Controllers\Api\V1\Borrower\MyApplication\MyApplicationController;
use App\Http\Controllers\Api\V1\Borrower\PreQualification\PreQualificationController;
use App\Http\Controllers\Api\V1\Borrower\FundApplication\FundingApplicationController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderOverviewController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderStepGuideController;
use App\Http\Controllers\Api\V1\Borrower\FundApplication\FundingApplicationDocumentController;


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

        // 📊 Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Pre-Qualification
        Route::get('/pre-qualification', [PreQualificationController::class, 'index']);
        Route::get('/pre-qualification/{id}', [PreQualificationController::class, 'show']);

        Route::post('/pre-qualification/personal-info', [PreQualificationController::class, 'personalInfo']);
        Route::post('/pre-qualification/business-info', [PreQualificationController::class, 'businessInfo']);
        Route::post('/pre-qualification/funding-info', [PreQualificationController::class, 'fundingInfo']);
        Route::post('/pre-qualification/submit', [PreQualificationController::class, 'submit']);


        Route::group(['prefix' => 'profile',], function () {
            Route::get('/personal-info', [PersonalInfoController::class, 'index']);
            Route::post('/personal-info', [PersonalInfoController::class, 'update']);
            Route::get('/business-info', [BusinessInfoController::class, 'index']);
            Route::post('/business-info', [BusinessInfoController::class, 'update']);
            Route::get('/financial-info', [FinancialInfoController::class, 'index']);
            Route::post('/financial-info', [FinancialInfoController::class, 'update']);
            Route::get('/completion', [ProfileCompletionController::class, 'check']);
        });

        Route::get('/get-advisor', [BorrowerController::class, 'getAdvisor']);

        Route::group(['prefix' => 'myapplication',], function () {
            Route::get('/pre-qualification', [MyApplicationController::class, 'preQualification']);
            Route::get('/funding-application', [MyApplicationController::class, 'fundingApplication']);
            Route::post('/funding-application/co-borrower-validation', [MyApplicationController::class, 'coBorrowerValidation']);
        });

        Route::prefix('documents')->group(function () {
            Route::get('borrower-document-types', [DocumentController::class, 'documentTypes']);
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/upload', [DocumentController::class, 'store']);
            Route::delete('/{id}', [DocumentController::class, 'destroy']);
        });


        Route::prefix('credit-builder')->group(function () {
            Route::get('/my-progress', [CreditBuilderOverviewController::class, 'index']);
            Route::get('/tier-wise-tasklist', [CreditBuilderStepGuideController::class, 'index']);
            Route::post('/task-verify-request', [CreditBuilderStepGuideController::class, 'taskVerifyRequest']);

            Route::get('task-overview', [CreditBuilderOverviewController::class, 'taskOverview']);
        });


        Route::prefix('funding-application')->group(function () {
            Route::get('/business-info', [FundingApplicationController::class, 'getBusinessInfo']);
            Route::post('/business-info', [FundingApplicationController::class, 'saveBusinessInfo']);

            Route::get('/ownership-info', [FundingApplicationController::class, 'getOwnershipInfo']);
            Route::post('/ownership-info', [FundingApplicationController::class, 'saveOwnershipInfo']);

            Route::get('/financial-info', [FundingApplicationController::class, 'getFinancialInfo']);
            Route::post('/financial-info', [FundingApplicationController::class, 'saveFinancialInfo']);

            Route::get('/funding-request', [FundingApplicationController::class, 'getFundingRequest']);
            Route::post('/funding-request', [FundingApplicationController::class, 'saveFundingRequest']);

            Route::get('/disclosures-and-consent', [FundingApplicationController::class, 'getDisclosuresAndConsent']);
            Route::post('/disclosures-and-consent', [FundingApplicationController::class, 'saveDisclosuresAndConsent']);


            Route::get('/federal-compliance-disclosures', [FundingApplicationController::class, 'getFederalComplianceDisclosures']);
            Route::post('/federal-compliance-disclosures', [FundingApplicationController::class, 'saveFederalComplianceDisclosures']);

            Route::get('/attestation-authorization', [FundingApplicationController::class, 'getAttestationAndAuthorization']);
            Route::post('/attestation-authorization', [FundingApplicationController::class, 'saveAttestationAndAuthorization']);


            Route::get('/documents/{funding_application_id}', [FundingApplicationController::class, 'getDocuments']);
            Route::post('/documents/upload', [FundingApplicationController::class, 'uploadDocument']);
            Route::delete('/documents/{id}', [FundingApplicationController::class, 'deleteDocument']);
        });

        Route::prefix('invoice')->group(function () {
            Route::post('/', [InvoiceController::class, 'index']);
            Route::post('/stats', [InvoiceController::class, 'stats']);
            Route::get('/check-unpaid-invoice', [InvoiceController::class, 'checkUnpaidInvoice']);
        });

        Route::prefix('payments')->group(function () {
            Route::post('fetch-payments', [PaymentController::class, 'index']);
            Route::post('stats', [PaymentController::class, 'stats']);
            Route::get('{id}', [PaymentController::class, 'show']);
        });

        Route::get('/user-details', BorrowerUserDetailController::class);

        Route::get('/settings', [SettingController::class, 'show']);

        Route::post('/process-payment', [GlobalPaymentController::class, 'processPayment']);


        // auth profile
        Route::prefix('auth-profile')->group(function () {
            Route::post('update-password', [AuthProfileController::class, 'updatePassword']);
        });
    });
});
