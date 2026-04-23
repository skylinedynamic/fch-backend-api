<?php

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Http\Controllers\Api\V1\FCH\Common\User\UserController;
use App\Http\Controllers\Api\V1\Global\General\LocationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\SettingController;
use App\Http\Controllers\Api\V1\FCH\Common\Contact\ContactController;
use App\Http\Controllers\Api\V1\FCH\Common\Invoice\InvoiceController;
use App\Http\Controllers\Api\V1\GlobalPayment\GlobalPaymentController;
use App\Http\Controllers\Api\V1\FCH\Common\Payment\PaymentController as CommonPaymentController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\Department\DepartmentController;
use App\Http\Controllers\Api\V1\FCH\Common\Statistics\StatisticsController;
use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\PermissionController;
use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder\VerificationCenterController;
use App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController as V1TwoFaController;
use App\Http\Controllers\Api\V1\FCH\Common\Tier\TierController as CommonTierController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerDocumentsChecklistController;
use App\Http\Controllers\Api\V1\FCH\Common\TierTask\TierTaskController as CommonTierTaskController;
use App\Http\Controllers\Api\V1\Global\Notification\NotificationController as V1NotificationController;
use App\Http\Controllers\Api\V1\FCH\Common\SubscriptionPlan\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\FCH\Global\UserDetailController;
use App\Http\Controllers\Api\V1\FCH\Common\AuthProfile\AuthProfileController;
use App\Http\Controllers\Api\V1\FCH\Common\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Admin\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\AppConfigurationController;

use App\Http\Controllers\Api\V1\FCH\Common\ActivityLog\ActivityLogController;
use App\Http\Controllers\Api\V1\FCH\Common\Resource\ResourceController as CommonResourceController;

Route::prefix('v1')->group(function () {
    // V1 auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [V1AuthController::class, 'login']);
        Route::post('/login/2fa/confirm', [V1TwoFaController::class, 'login_confirm']);
        Route::post('/forgot', [V1AuthController::class, 'forgot']);
        Route::post('/reset', [V1AuthController::class, 'reset']);

        Route::middleware('auth:api')->group(function () {
            // V1 auth routes
            Route::post('/logout', [V1AuthController::class, 'logout']);
        });
    });

    // Location routes
    Route::get('common/locations', [LocationController::class, 'index']);


    Route::middleware('auth:api')->group(function () {

        // V1 2FA routes
        Route::prefix('2fa')->group(function () {
            Route::post('/setup', [V1TwoFaController::class, 'setup']);
            Route::post('/verify', [V1TwoFaController::class, 'verify']);
            Route::post('/disable', [V1TwoFaController::class, 'disable']);
            Route::post('/resend', [V1TwoFaController::class, 'resend']);
            Route::get('/status', [V1TwoFaController::class, 'status']);
            Route::post('/recovery-codes/regenerate', [V1TwoFaController::class, 'regenerateRecoveryCodes']);
        });
        Route::prefix('common')->group(function () {

            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('refresh-user', [CommonController::class, 'refreshUser']);

            Route::post('roles/fetch-roles', [RoleController::class, 'fetchRoles']);
            Route::post('roles/destroy', [RoleController::class, 'destroy']);
            Route::apiResource('roles', RoleController::class)->except(['destroy']);

            Route::post('users/{user}/roles', [UserRoleController::class, 'assignRoles']);
            Route::post('users/{user}/permissions', [UserRoleController::class, 'assignPermissions']);

            Route::post('employees/fetch-employees', [EmployeeController::class, 'fetchEmployees']);
            Route::post('employees/fetch-department-status', [EmployeeController::class, 'fetchDepartmentStatus']);
            Route::post('employees/multiple-delete', [EmployeeController::class, 'multipleDelete']);
            Route::get('employees-next-id', [EmployeeController::class, 'nextId']);
            Route::apiResource('employees', EmployeeController::class);

            Route::post('users/fetch-users', [UserController::class, 'fetchUsers']);
            Route::post('users/multiple-delete', [UserController::class, 'multipleDelete']);
            Route::post('users/check-deletable', [UserController::class, 'checkDeletable']);
            Route::apiResource('users', UserController::class);

            Route::post('permissions', [PermissionController::class, 'index']);

            Route::post('departments', [DepartmentController::class, 'store']);
            Route::post('departments/fetch-departments', [DepartmentController::class, 'fetchDepartments']);
            Route::get('departments/{id}', [DepartmentController::class, 'show']);
            Route::delete('departments/{id}', [DepartmentController::class, 'destroy']);

            //Borrower document types
            Route::get('borrower-document-types', [BorrowerController::class, 'documentTypes']);

            Route::post('borrowers/personal-details', [BorrowerController::class, 'updatePersonalDetails']);
            Route::post('borrowers/business-financials', [BorrowerController::class, 'updateBusinessFinancials']);

            // Separate API for Client Info and Business Financials
            Route::get('borrowers/personal-details/{id}', [BorrowerController::class, 'getPersonalDetails']);
            Route::get('borrowers/business-financials/{id}', [BorrowerController::class, 'getBusinessFinancials']);

            // Borrower routes
            Route::post('borrowers/fetch-all-borrowers', [BorrowerController::class, 'index']);
            Route::post('borrowers', [BorrowerController::class, 'store']);
            Route::post('borrowers/{id}', [BorrowerController::class, 'update']);
            Route::post('borrowers/{id}/business-financials', [BorrowerController::class, 'BusinessFinancials']);
            Route::get('borrowers/{id}', [BorrowerController::class, 'show']);
            Route::post('borrowers-multiple-delete', [BorrowerController::class, 'multipleDelete']);
            Route::post('borrowers/check-deletable', [BorrowerController::class, 'checkDeletable']);
            Route::post('borrowers-get-all-users', [BorrowerController::class, 'getAllUsers']);
            Route::post('borrowers-profile-states-details-count/{borrowerId}', [BorrowerController::class, 'profileStatsCount']);
            Route::post('borrowers-documents/upload', [BorrowerController::class, 'uploadDocument']);
            Route::post('borrowers-documents/{borrowerId}', [BorrowerController::class, 'fetchBorrowerDocuments']);

            Route::post('borrowers-documents/update-status/{id}', [BorrowerController::class, 'updateDocumentStatus']);
            Route::post('borrowers-documents-delete/{id}', [BorrowerController::class, 'deleteDocument']);
            Route::get('borrowers-documents/download/{documentId}', [BorrowerController::class, 'downloadDocument']);
            Route::post('borrowers-tiers', [BorrowerController::class, 'getTiers']);
            Route::get('borrowers/get-borrower-profile/{borrowerId}', [BorrowerController::class, 'getBorrowerProfile']);


            //Request Application
            Route::prefix('request-application')->group(function () {
                Route::post('pre-qualification', [RequestApplicationController::class, 'getPreQualification']);
                Route::get('pre-qualification/card-summary', [RequestApplicationController::class, 'getPreQualificationCardSummary']);
                Route::get('pre-qualification/{id}', [RequestApplicationController::class, 'getPreQualificationById']);
                Route::post('pre-qualification/status-update', [RequestApplicationController::class, 'preQualificationStatusUpdate']);
                Route::post('funding-application', [RequestApplicationController::class, 'getFundingApplication']);
                Route::get('funding-application/card-summary', [RequestApplicationController::class, 'getFundingApplicationCardSummary']);
                Route::get('funding-application/{id}', [RequestApplicationController::class, 'getFundingApplicationById']);
                Route::post('funding-application/status-update', [RequestApplicationController::class, 'fundingApplicationStatusUpdate']);
            });

            // Borrower Documents Checklist routes
            Route::get('borrower-documents-checklist/{borrowerId}', [BorrowerDocumentsChecklistController::class, 'getChecklist']);
            Route::post('borrower-documents-checklist/save/{borrowerId}', [BorrowerDocumentsChecklistController::class, 'saveChecklist']);

            Route::post('tiers/fetch-all-tiers', [CommonTierController::class, 'index']);
            Route::post('tier-tasks/fetch-all-tasks', [CommonTierTaskController::class, 'index']);

            Route::prefix('credit-builder')->group(function () {
                Route::post('verification-center-fetch-all', [VerificationCenterController::class, 'fetchAll']);
                Route::post('verification-center-borrower-requests', [VerificationCenterController::class, 'fetchBorrowerRequests']);
                Route::post('verification-center-status-update', [VerificationCenterController::class, 'statusUpdate']);
            });

            // Appointment routes
            Route::prefix('appointments')->group(function () {
                Route::post('check-availability', [AppointmentController::class, 'checkAvailability']);
                Route::post('fetch-all', [AppointmentController::class, 'index']);
                Route::post('fetch-selectable-users', [AppointmentController::class, 'selectableUsers']);
            });
            Route::apiResource('appointments', AppointmentController::class);

            // Contact routes
            Route::post('contacts/fetch-contacts', [ContactController::class, 'fetchContacts']);
            Route::post('contacts/multiple-delete', [ContactController::class, 'multipleDelete']);
            Route::apiResource('contacts', ContactController::class);

            // Activity Log routes
            Route::prefix('activity-logs')->group(function () {
                Route::post('fetch-all-activities', [ActivityLogController::class, 'index']);
                Route::post('attachments/download', [ActivityLogController::class, 'downloadAttachment']);
                Route::get('/', [ActivityLogController::class, 'index']);
                Route::get('/{id}', [ActivityLogController::class, 'show']);
                Route::post('/', [ActivityLogController::class, 'store']);
            });

            // Common routes
            Route::post('get-all-users', [CommonController::class, 'getAllUsers']);
            Route::get('user-count', [CommonController::class, 'getUserCount']);
            Route::get('user-details', [UserDetailController::class, '__invoke']);

            // Statistics routes
            Route::post('stats', [StatisticsController::class, 'stats']);

            // Lead routes
            Route::post('leads/fetch-leads', [LeadController::class, 'fetch']);
            Route::get('leads-stats', [LeadController::class, 'stats']);

            // Explicitly define Leads Resource and separate split endpoints
            Route::get('leads', [LeadController::class, 'index']);
            Route::get('leads/{id}', [LeadController::class, 'show']);
            Route::delete('leads/{id}', [LeadController::class, 'destroy']);

            Route::post('leads/details', [LeadController::class, 'storeDetails']);
            Route::post('leads/{id}/details', [LeadController::class, 'updateDetails']);
            Route::post('leads/{id}/contact-info', [LeadController::class, 'updateContactInfo']);

            Route::post('leads/{id}/convert', [LeadController::class, 'convert']);

            //  Invoices
            Route::post('invoices/fetch-invoices', [InvoiceController::class, 'index']);
            Route::post('invoices/stats', [InvoiceController::class, 'stats']);
            Route::get('invoices/{id}', [InvoiceController::class, 'show']);
            Route::get('invoices/{id}/download', [InvoiceController::class, 'download']);
            Route::post('invoices/{id}/send', [InvoiceController::class, 'send']);
            Route::post('settings', [SettingController::class, 'store']);
            Route::get('settings', [SettingController::class, 'show']);

            //  Payments
            Route::post('payments/fetch-payments', [CommonPaymentController::class, 'index']);
            Route::post('payments/stats', [CommonPaymentController::class, 'stats']);
            Route::get('payments/{id}', [CommonPaymentController::class, 'show']);

            // Partner Plans
            Route::get('partner-plans', [SubscriptionPlanController::class, 'index']);
            Route::get('borrower-plans', [SubscriptionPlanController::class, 'borrowerPlans']);
            Route::get('non-host-borrower-plans', [SubscriptionPlanController::class, 'nonHostBorrowerPlans']);
            Route::get('partner-plans/{idOrSlug}', [SubscriptionPlanController::class, 'show']);

            // Commissions
            Route::prefix('commissions')->group(function () {
                Route::get('/{id}', [CommissionController::class, 'show']);
                Route::post('/', [CommissionController::class, 'index']);
            });


            // auth profile
            Route::prefix('auth-profile')->group(function () {
                Route::post('update-password', [AuthProfileController::class, 'updatePassword']);
            });

            // App Configurations (Settings)
            Route::prefix('app-config')->group(function () {
                Route::post('/show', [AppConfigurationController::class, 'getSetting']);
                Route::post('/save', [AppConfigurationController::class, 'saveSetting']);
            });

            // Agreement templates
            Route::prefix('agreement-templates')->group(function () {
                Route::get('/', [AgreementTemplateController::class, 'index']);
                Route::post('/save/{id?}', [AgreementTemplateController::class, 'store']);
                Route::post('/show', [AgreementTemplateController::class, 'show']);
                Route::post('/update-status/{id}', [AgreementTemplateController::class, 'updateStatus']);
            });

            // Agreement signing
            Route::prefix('agreement-sign')->group(function () {
                Route::get('/check', [AgreementSignController::class, 'checkAgreementSign']);
                Route::post('/submit', [AgreementSignController::class, 'submitSign']);
                Route::get('/html/{userId?}', [AgreementSignController::class, 'getSignedHtml']);
                Route::get('/download/{userId?}', [AgreementSignController::class, 'downloadAgreement']);
                Route::post('/cancel/{userId?}', [AgreementSignController::class, 'cancelSign']);
            });

            // 📚 Resources
            Route::prefix('resources')->group(function () {
                Route::get('/', [CommonResourceController::class, 'index']);
                Route::get('/download/{id}', [CommonResourceController::class, 'download']);
            });
        });

        Route::post('process-payment', [GlobalPaymentController::class, 'processPayment']);
        Route::post('process-payment-for-users', [GlobalPaymentController::class, 'processPaymentForUsers']);

        // V1 Notification routes
        Route::prefix('notifications')->group(function () {
            Route::post('/test-email', [V1NotificationController::class, 'sendTestEmail']);
        });
    });
});
