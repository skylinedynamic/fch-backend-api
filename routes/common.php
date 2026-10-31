<?php

use App\Http\Controllers\Api\V1\Global\Branding\BrandingController;
use App\Http\Controllers\Api\V1\Global\Account\EmailChangeConfirmationController;
use App\Http\Controllers\Api\V1\Global\Account\EmailChangeLinkController;
use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SiteSettingsController;
use App\Http\Controllers\Api\V1\FCH\Common\Billing\BillingController;
use App\Http\Controllers\Api\V1\FCH\Common\MySubscription\MySubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditAccountController as AdminCreditAccountController;
use App\Http\Controllers\Api\V1\FCH\Admin\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\FCH\Common\ActivityLog\ActivityLogController;
use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Http\Controllers\Api\V1\FCH\Common\Appointment\AppointmentController;
use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\FCH\Common\AuthProfile\AuthProfileController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerCapitalAccessController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditAuthorization\CreditAuthorizationConsentController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerDocumentsChecklistController;
use App\Http\Controllers\Api\V1\FCH\Common\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Http\Controllers\Api\V1\FCH\Common\Partner\PartnerOptionController;
use App\Http\Controllers\Api\V1\FCH\Common\Contact\ContactController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder\CapitalAccessController as CommonCapitalAccessController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder\VerificationCenterController;
use App\Http\Controllers\Api\V1\FCH\Common\Department\DepartmentController;
use App\Http\Controllers\Api\V1\FCH\Common\Compensation\CompensationController;
use App\Http\Controllers\Api\V1\FCH\Common\Account\EmailChangeController;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\FCH\Common\Funding\FundingQualificationController;
use App\Http\Controllers\Api\V1\FCH\Common\FundingRequest\FundingRequestController;
use App\Http\Controllers\Api\V1\FCH\Common\Invoice\InvoiceController;
use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Http\Controllers\Api\V1\FCH\Common\PartnerFundApplication\PartnerFundingApplicationController;
use App\Http\Controllers\Api\V1\FCH\Common\Payment\PaymentController as CommonPaymentController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\PartnerPaymentProviderController;
use App\Http\Controllers\Api\V1\FCH\Common\Reminder\ReminderController;
use App\Http\Controllers\Api\V1\FCH\Common\Notification\NotificationInboxController;
use App\Http\Controllers\Api\V1\FCH\Common\Resource\ResourceController as CommonResourceController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\PermissionController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\AppConfigurationController as CommonAppConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\OpenAiConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\PreQualificationAgentConfigController;
use App\Http\Controllers\Api\V1\FCH\Common\Assistant\WlpAssistantController;
use App\Http\Controllers\Api\V1\FCH\Common\Assistant\AssistantConfigController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\FallbackMailConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\MailConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\SmsConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\TenantConfigController;
use App\Http\Controllers\Api\V1\FCH\Common\Statistics\StatisticsController;
use App\Http\Controllers\Api\V1\FCH\Common\SubscriptionPlan\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\FCH\Common\Subscription\SubscriptionController as CommonSubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Common\Tier\TierController as CommonTierController;
use App\Http\Controllers\Api\V1\FCH\Common\TierTask\TierTaskController as CommonTierTaskController;
use App\Http\Controllers\Api\V1\FCH\Common\User\UserController;
use App\Http\Controllers\Api\V1\FCH\Global\UserDetailController;
use App\Http\Controllers\Api\V1\Global\General\LocationController;
use App\Http\Controllers\Api\V1\Global\Notification\NotificationController as V1NotificationController;
use App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController as V1TwoFaController;
use App\Http\Controllers\Api\V1\GlobalPayment\UnifiedPaymentController;
use App\Http\Controllers\DomainResolverController;
use App\Http\Controllers\Api\V1\FCH\Common\EmbedForm\EmbedFormController;
use Illuminate\Support\Facades\Route;




// Internal domain resolver — stateless, no session/cookies
Route::get('/resolve-custom-domain', [DomainResolverController::class, 'resolve']);
Route::get('/caddy/domain-check', [DomainResolverController::class, 'caddyCheck']);

// Tenant brand as a render-blocking stylesheet, linked from each portal's
// index.html. Deliberately outside /v1 and unauthenticated: it has to load
// before the app boots, on a cold visit with no token and no localStorage.
Route::get('/branding.css', [BrandingController::class, 'css']);

// Tenant title, description, Open Graph tags and favicon as an HTML fragment,
// included into each portal's index.html by Nginx (SSI) so link previews -
// which never run JavaScript - show the partner's own name and logo.
Route::get('/portal-meta', [BrandingController::class, 'meta']);

// Email change links — confirm (sent to the new address), cancel and undo (sent
// to the old one). Public: each link's signature is the authority (checked in
// the controller). Opening a link (GET) only shows a page; its form POSTs back
// to the same signed URL to act. These are browser pages, not JSON: the `web`
// group gives them the session their forms' CSRF token needs.
Route::prefix('email-change')
    ->middleware('web')
    ->controller(EmailChangeConfirmationController::class)
    ->whereNumber('user')
    ->middleware('throttle:10,1')
    ->group(function () {
        Route::get('confirm/{user}/{hash}', 'showConfirm')->name('email-change.confirm');
        Route::post('confirm/{user}/{hash}', 'confirm');
        Route::get('cancel/{user}/{hash}', 'showCancel')->name('email-change.cancel');
        Route::post('cancel/{user}/{hash}', 'cancel');
        Route::get('undo/{user}/{hash}', 'showRevert')->name('email-change.revert');
        Route::post('undo/{user}/{hash}', 'revert');
    });

// The same links as sent now: they open on the account's portal, whose page
// reads (GET) and acts on (POST) a link here as JSON, with the link's expires
// and signature passed along unchanged. Signed over the relative URL.
Route::prefix('v1/email-change')
    ->controller(EmailChangeLinkController::class)
    ->whereNumber('user')
    ->middleware('throttle:10,1')
    ->group(function () {
        Route::get('confirm/{user}/{hash}', 'showConfirm')->name('email-change.link.confirm');
        Route::post('confirm/{user}/{hash}', 'confirm');
        Route::get('cancel/{user}/{hash}', 'showCancel')->name('email-change.link.cancel');
        Route::post('cancel/{user}/{hash}', 'cancel');
        Route::get('undo/{user}/{hash}', 'showRevert')->name('email-change.link.undo');
        Route::post('undo/{user}/{hash}', 'revert');
    });


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

    Route::get('common/get-theme', [CommonController::class, 'getTheme']);

    // Branding only — skips the email-config and payment-provider queries that
    // get-theme also runs, so the portals can paint the brand without waiting on
    // them. Returns the precomputed CSS variable map alongside the raw values.
    Route::get('common/branding', [BrandingController::class, 'show']);

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
        // Partner portal API; borrowers only reach the routes the borrower
        // portal calls - see RejectBorrowersFromPartnerPortal.
        Route::prefix('common')->middleware('partner-portal')->group(function () {

            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('refresh-user', [CommonController::class, 'refreshUser']);
            Route::get('partner-theme', [CommonController::class, 'getTheme']);

            Route::post('roles/fetch-roles', [RoleController::class, 'fetchRoles']);
            Route::post('roles/destroy', [RoleController::class, 'destroy']);
            Route::apiResource('roles', RoleController::class)->except(['destroy']);

            Route::post('users/{user}/roles', [UserRoleController::class, 'assignRoles']);
            Route::post('users/{user}/permissions', [UserRoleController::class, 'assignPermissions']);

            Route::post('employees/fetch-employees', [EmployeeController::class, 'fetchEmployees']);
            Route::post('employees/fetch-department-status', [EmployeeController::class, 'fetchDepartmentStatus']);
            Route::post('employees/multiple-delete', [EmployeeController::class, 'multipleDelete']);
            Route::get('employees-next-id', [EmployeeController::class, 'nextId']);
            Route::get('employees-available-for-user', [EmployeeController::class, 'availableForUser']);
            Route::apiResource('employees', EmployeeController::class);

            // Compensation ("Paid By") is stored separately from the entity that
            // owns it, so it has its own endpoints — never the employee ones. The
            // entity is identified by entity_type/entity_id in the body, and the
            // one write endpoint handles both the first save and later edits.
            Route::post('compensations/fetch-compensation', [CompensationController::class, 'show']);
            Route::post('compensations', [CompensationController::class, 'store']);

            // A pending sign-in email change (started by saving an edit form,
            // completed by the emailed link) can be resent or withdrawn here.
            Route::post('email-change/resend', [EmailChangeController::class, 'resend']);
            Route::post('email-change/cancel', [EmailChangeController::class, 'cancel']);

            Route::post('users/fetch-users', [UserController::class, 'fetchUsers']);
            Route::post('users/multiple-delete', [UserController::class, 'multipleDelete']);
            Route::post('users/check-deletable', [UserController::class, 'checkDeletable']);
            // No destroy(): UserController has none - deleting is users/multiple-delete.
            Route::apiResource('users', UserController::class)->except(['destroy']);

            Route::post('permissions', [PermissionController::class, 'index']);

            Route::post('departments', [DepartmentController::class, 'store']);
            Route::post('departments/fetch-departments', [DepartmentController::class, 'fetchDepartments']);
            Route::get('departments/{id}', [DepartmentController::class, 'show']);
            Route::delete('departments/{id}', [DepartmentController::class, 'destroy']);

            //Borrower document types
            Route::get('borrower-document-types', [BorrowerController::class, 'documentTypes']);

            // Business Financials fetch — dedicated endpoint
            Route::get('borrowers/business-financials/{id}', [BorrowerController::class, 'getBusinessFinancials']);
            Route::get('borrowers/{id}/business-financials', [BorrowerController::class, 'getBusinessFinancials']);

            // Credit Profile routes — must be before wildcard borrowers/{id}
            // Uploading, saving and removing it: the host partner's team only.
            Route::middleware('host-partner')->group(function () {
                Route::post('borrowers/parse-credit-profile', [BorrowerController::class, 'parseCreditProfile']);
                Route::post('borrowers/save-credit-profile', [BorrowerController::class, 'saveCreditProfile']);
                Route::delete('borrowers/{borrowerId}/credit-profile', [BorrowerController::class, 'deleteCreditProfile'])
                    ->whereNumber('borrowerId');
            });
            Route::get('borrowers/get-credit-profile/{borrowerId}', [BorrowerController::class, 'getCreditProfile']);

            // The PDF sent to iSoftPull when they ask us to prove a client
            // authorised a pull. Host partner only, enforced in the controller.
            //
            // Only the file has a route of its own - the records themselves ride
            // along with the credit profile request details, which is the screen
            // that shows them.
            Route::get('credit-authorizations/{id}/download', [CreditAuthorizationConsentController::class, 'download']);

            // Borrower routes
            // MUST stay above the wildcard `borrowers/{id}` below: routes match in
            // declaration order, so the wildcard would swallow /borrowers/archive
            // and run update() with id = "archive".
            // Same reason as archive below: a literal path must precede the wildcard.
            Route::post('borrowers/check-deletable', [BorrowerController::class, 'checkDeletable']);

            Route::post('borrowers/archive', [BorrowerController::class, 'archive'])
                ->middleware('permission:update clients');
            Route::post('borrowers/unarchive', [BorrowerController::class, 'unarchive'])
                ->middleware('permission:update clients');

            Route::post('borrowers/fetch-all-borrowers', [BorrowerController::class, 'index']);
            Route::post('borrowers', [BorrowerController::class, 'store']);
            Route::post('borrowers/{id}', [BorrowerController::class, 'update']);
            Route::post('borrowers/{id}/business-financials', [BorrowerController::class, 'BusinessFinancials']);
            Route::get('borrowers/{id}', [BorrowerController::class, 'show']);
            Route::get('borrowers/{id}/capital-access-overview', [BorrowerCapitalAccessController::class, 'getOverview']);
            Route::get('borrowers/{id}/capital-access-strategies', [BorrowerCapitalAccessController::class, 'strategiesForClient']);
            Route::post('borrowers-multiple-delete', [BorrowerController::class, 'multipleDelete']);

            Route::post('borrowers-get-all-users', [BorrowerController::class, 'getAllUsers']);
            Route::post('borrowers-profile-states-details-count/{borrowerId}', [BorrowerController::class, 'profileStatsCount']);
            Route::post('borrowers-documents/upload', [BorrowerController::class, 'uploadDocument']);
            Route::post('borrowers-documents/{borrowerId}', [BorrowerController::class, 'fetchBorrowerDocuments']);


            Route::post('borrowers-documents/update-status/{id}', [BorrowerController::class, 'updateDocumentStatus']);
            Route::post('borrowers-documents-delete/{id}', [BorrowerController::class, 'deleteDocument']);
            Route::get('borrowers-documents/download/{documentId}', [BorrowerController::class, 'downloadDocument']);
            Route::post('borrowers-tiers', [BorrowerController::class, 'getTiers']);
            Route::get('doc-types', [BorrowerController::class, 'documentTypes']);
            Route::get('borrowers/get-borrower-profile/{borrowerId}', [BorrowerController::class, 'getBorrowerProfile']);


            //Request Application
            Route::prefix('request-application')->group(function () {
                Route::post('pre-qualification', [RequestApplicationController::class, 'getPreQualification']);
                Route::get('pre-qualification/card-summary', [RequestApplicationController::class, 'getPreQualificationCardSummary']);
                Route::get('pre-qualification/{id}', [RequestApplicationController::class, 'getPreQualificationById']);
                Route::post('pre-qualification/status-update', [RequestApplicationController::class, 'preQualificationStatusUpdate']);
                Route::post('pre-qualification/{id}/run-ai', [RequestApplicationController::class, 'runAiPreQualification']);
                Route::post('funding-application', [RequestApplicationController::class, 'getFundingApplication']);
                Route::get('funding-application/card-summary', [RequestApplicationController::class, 'getFundingApplicationCardSummary']);
                Route::get('funding-application/{id}', [RequestApplicationController::class, 'getFundingApplicationById']);
                Route::post('funding-application/{id}/audit-history', [RequestApplicationController::class, 'getAuditHistory']);
                Route::get('funding-application/{id}/download-application', [RequestApplicationController::class, 'downloadApplication']);
                Route::get('funding-application/{id}/download-signature', [RequestApplicationController::class, 'downloadSignature']);
                Route::get('funding-application/{id}/download-zip', [RequestApplicationController::class, 'downloadZip']);
                Route::get('download-history/{historyId}/download', [RequestApplicationController::class, 'downloadFromHistory']);
                Route::post('funding-application/status-update', [RequestApplicationController::class, 'fundingApplicationStatusUpdate']);
            });

            // Borrower Funding Application Wizard (For Admin/Partner)
            #SCRUM-67
            Route::prefix('funding-application')->group(function () {
                Route::get('business-info', [PartnerFundingApplicationController::class, 'getBusinessInfo']);
                Route::post('business-info', [PartnerFundingApplicationController::class, 'saveBusinessInfo']);
                Route::get('ownership-info', [PartnerFundingApplicationController::class, 'getOwnershipInfo']);
                Route::post('ownership-info', [PartnerFundingApplicationController::class, 'saveOwnershipInfo']);
                Route::get('financial-info', [PartnerFundingApplicationController::class, 'getFinancialInfo']);
                Route::post('financial-info', [PartnerFundingApplicationController::class, 'saveFinancialInfo']);
                Route::get('funding-request', [PartnerFundingApplicationController::class, 'getFundingRequest']);
                Route::post('funding-request', [PartnerFundingApplicationController::class, 'saveFundingRequest']);
                Route::get('disclosures-and-consent', [PartnerFundingApplicationController::class, 'getDisclosuresAndConsent']);
                Route::post('disclosures-and-consent', [PartnerFundingApplicationController::class, 'saveDisclosuresAndConsent']);
                Route::get('federal-compliance-disclosures', [PartnerFundingApplicationController::class, 'getFederalComplianceDisclosures']);
                Route::post('federal-compliance-disclosures', [PartnerFundingApplicationController::class, 'saveFederalComplianceDisclosures']);
                Route::get('documents/{funding_application_id}', [PartnerFundingApplicationController::class, 'getDocuments']);
                Route::post('documents/upload', [PartnerFundingApplicationController::class, 'uploadDocument']);
                Route::delete('documents/{id}', [PartnerFundingApplicationController::class, 'deleteDocument']);
                Route::get('documents/{id}/download', [PartnerFundingApplicationController::class, 'downloadDocument']);
                Route::get('attestation-authorization', [PartnerFundingApplicationController::class, 'getAttestationAndAuthorization']);
                Route::post('attestation-authorization', [PartnerFundingApplicationController::class, 'saveAttestationAndAuthorization']);
                Route::post('co-borrower-validation', [PartnerFundingApplicationController::class, 'coBorrowerValidation']);
            });

            // Funding Request
            Route::prefix('funding-request')->group(function () {
                Route::post('', [FundingRequestController::class, 'getFundingRequest']);
                Route::get('{id}', [FundingRequestController::class, 'getFundingRequestById']);
                Route::post('status-update', [FundingRequestController::class, 'fundingRequestStatusUpdate']);
                Route::post('assign-lender', [FundingRequestController::class, 'assignLender']);
            });

            // Funding Qualification endpoints
            Route::prefix('funding-qualification')->group(function () {
                Route::get('{fundingId}/readiness-factors', [FundingQualificationController::class, 'getReadinessFactors']);
                Route::get('client-readiness-factors/{borrowerId}/{fundingId}', [FundingQualificationController::class, 'getClientReadinessFactors']);
            });

            // Borrower Documents Checklist routes
            Route::get('borrower-documents-checklist/{borrowerId}', [BorrowerDocumentsChecklistController::class, 'getChecklist']);
            Route::post('borrower-documents-checklist/save/{borrowerId}', [BorrowerDocumentsChecklistController::class, 'saveChecklist']);

            Route::post('tiers/fetch-all-tiers', [CommonTierController::class, 'index']);
            Route::post('tier-tasks/fetch-all-tasks', [CommonTierTaskController::class, 'index']);

            // Host-only screens in the partner portal (HostRoute): the
            // Verification Center and the Capital Access catalogue.
            Route::prefix('credit-builder')->middleware('host-only')->group(function () {
                Route::post('verification-center-fetch-all', [VerificationCenterController::class, 'fetchAll']);
                Route::post('verification-center-borrower-requests', [VerificationCenterController::class, 'fetchBorrowerRequests']);
                Route::post('verification-center-status-update', [VerificationCenterController::class, 'statusUpdate']);

                // Fetch all active credit accounts for Capital Access builder (no pagination)
                Route::post('credit-accounts/all', [AdminCreditAccountController::class, 'allActive']);
            });

            // Capital Access CRUD
            Route::prefix('capital-accesses')->middleware('host-only')->group(function () {
                Route::post('fetch-all', [CommonCapitalAccessController::class, 'index']);
                Route::post('/', [CommonCapitalAccessController::class, 'store']);
                Route::get('{id}', [CommonCapitalAccessController::class, 'show']);
                Route::put('{id}', [CommonCapitalAccessController::class, 'update']);
                Route::patch('{id}/status', [CommonCapitalAccessController::class, 'updateStatus']);
                Route::delete('{id}', [CommonCapitalAccessController::class, 'destroy']);
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
                Route::match(['patch', 'post'], '/{id}/visibility', [ActivityLogController::class, 'updateVisibility']);
                Route::match(['patch', 'post'], '/{id}/task-status', [ActivityLogController::class, 'updateTaskStatus']);
                Route::match(['put', 'post'], '/{id}', [ActivityLogController::class, 'update']);
                Route::delete('/{id}', [ActivityLogController::class, 'destroy']);
            });

            // Funding Sequencings
            Route::prefix('funding-sequencings')->group(function () {
                Route::post('fetch', [FundingRequestController::class, 'getFundingSequencing']);
                Route::get('grouped', [FundingRequestController::class, 'getGroupedFundingSequencing']);
                Route::get('client-funding-options/{borrower_id}', [FundingRequestController::class, 'getClientFundingOptions']);
            });



            // Common routes
            Route::post('get-all-users', [CommonController::class, 'getAllUsers']);
            // Every partner for the host's partner filter dropdowns (host only)
            Route::post('get-all-partners', [PartnerOptionController::class, 'index']);
            Route::get('user-count', [CommonController::class, 'getUserCount']);
            Route::get('user-details', [UserDetailController::class, '__invoke']);

            // Statistics routes
            Route::post('stats', [StatisticsController::class, 'stats']);

            // Lead routes
            Route::post('leads/fetch-leads', [LeadController::class, 'fetch']);
            Route::post('leads/multiple-delete', [LeadController::class, 'multipleDelete']);

            // Explicitly define Leads Resource and separate split endpoints
            Route::get('leads', [LeadController::class, 'index']);
            Route::get('leads/{id}', [LeadController::class, 'show']);

            Route::post('leads/details', [LeadController::class, 'storeDetails']);
            Route::post('leads/{id}/details', [LeadController::class, 'updateDetails']);
            Route::post('leads/{id}/contact-info', [LeadController::class, 'updateContactInfo']);

            Route::post('leads/{id}/convert', [LeadController::class, 'convert']);

            //  Invoices
            Route::post('invoices/fetch-invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{id}', [InvoiceController::class, 'show']);
            Route::get('invoices/{id}/download', [InvoiceController::class, 'download']);
            Route::post('invoices/{id}/send', [InvoiceController::class, 'send']);

            // Partner Payment Providers Config
            Route::get('partner-payment-providers', [PartnerPaymentProviderController::class, 'index']);
            Route::get('partner-payment-providers/{id}', [PartnerPaymentProviderController::class, 'show']);
            Route::post('partner-payment-providers', [PartnerPaymentProviderController::class, 'store']);
            Route::post('partner-payment-providers/status', [PartnerPaymentProviderController::class, 'updateStatus']);

            // Partner Tenant Config (for authenticated WL partners)
            Route::get('partner/tenant-config', [TenantConfigController::class, 'show']);
            Route::post('partner/tenant-config', [TenantConfigController::class, 'update']);

            // 💳 Billing
            Route::post('billing/fetch-upcoming', [BillingController::class, 'fetchUpcoming']);
            Route::get('billing/{id}', [BillingController::class, 'details']);

            // 📋 Non-Host Partner My Subscription Module
            Route::prefix('my-subscription')->group(function () {
                Route::get('/', [MySubscriptionController::class, 'index']);
                Route::post('update-payment-method', [MySubscriptionController::class, 'updateDefaultPaymentMethod']);
                Route::post('remove-default-payment-method', [MySubscriptionController::class, 'removeDefaultPaymentMethod']);
                Route::post('cancel-subscription', [MySubscriptionController::class, 'cancelSubscription']);
            });


            //  Payments
            Route::post('payments/fetch-payments', [CommonPaymentController::class, 'index']);
            Route::post('payments/{id}/approve', [CommonPaymentController::class, 'approve']);
            Route::post('payments/{id}/reject', [CommonPaymentController::class, 'reject']);
            Route::get('payments/{id}', [CommonPaymentController::class, 'show']);

            // Partner Plans
            Route::get('partner-plans', [SubscriptionPlanController::class, 'index']);
            Route::get('borrower-plans', [SubscriptionPlanController::class, 'borrowerPlans']);
            Route::get('partner-plans/{idOrSlug}', [SubscriptionPlanController::class, 'show']);

            // 🔄 Subscriptions Module Menu Routes (accessible to host and non-host partners)
            Route::prefix('subscriptions')->group(function () {
                Route::post('fetch-all', [CommonSubscriptionController::class, 'index']);
                Route::get('partner-borrower-fee', [CommonSubscriptionController::class, 'getPartnerBorrowerFee']);
                Route::get('{id}', [CommonSubscriptionController::class, 'show']);
            });

            // Commissions
            Route::prefix('commissions')->group(function () {
                Route::get('/{id}', [CommissionController::class, 'show']);
                Route::post('/', [CommissionController::class, 'index']);
            });


            // auth profile
            Route::prefix('auth-profile')->group(function () {
                Route::post('update-password', [AuthProfileController::class, 'updatePassword']);
                Route::post('update-profile', [AuthProfileController::class, 'updateProfile']);
                Route::post('update-profile-picture', [AuthProfileController::class, 'updateProfilePicture']);
            });

            // Common App Configurations (Key-Value)
            Route::prefix('app-configurations')->group(function () {
                Route::post('/', [CommonAppConfigurationController::class, 'index']);
                Route::post('/save', [CommonAppConfigurationController::class, 'store']);
            });

            // OpenAI configuration (host-only settings screen)
            // Access is decided in the admin UI by PartnerHostRoute (is_host and
            // an entity type of Partner); no per-role permission is required here.
            Route::prefix('openai')->group(function () {
                Route::get('config', [OpenAiConfigurationController::class, 'show']);
                Route::get('models', [OpenAiConfigurationController::class, 'models']);
                Route::post('config', [OpenAiConfigurationController::class, 'store']);
            });

            // Support assistant settings (host-only, enforced again inside the
            // controller regardless of what the admin UI hides): which OpenAI
            // Agent each assistant runs and whether it is switched on. The
            // agent's instructions are edited on the OpenAI platform, never here.
            Route::get('assistant-config', [AssistantConfigController::class, 'index']);
            Route::put('assistant-config/{type}', [AssistantConfigController::class, 'update']);

            // Pre-Qualification agent settings (host-only, enforced again inside
            // the controller): which OpenAI Agent runs each step of the
            // underwriting pipeline and which vector stores it searches. The
            // instructions and the output schema live on the OpenAI platform.
            Route::get('prequalification-agent-config', [PreQualificationAgentConfigController::class, 'index']);
            Route::put('prequalification-agent-config/{step}', [PreQualificationAgentConfigController::class, 'update']);

            // WLP support assistant chat — mirrors FCH_WLP_Assistant_OpenAPI_v1_1.yaml's
            // /wlp-assistant/* paths (see SupportAssistantService for the response envelope).
            Route::get('assistant/wlp/config', [WlpAssistantController::class, 'config']);
            Route::prefix('assistant/wlp')->middleware('throttle:assistant')->group(function () {
                Route::post('messages', [WlpAssistantController::class, 'sendMessage']);
                Route::get('conversations/{sessionId}/messages', [WlpAssistantController::class, 'history']);
                Route::get('sessions', [WlpAssistantController::class, 'index']);
                Route::post('sessions', [WlpAssistantController::class, 'store']);
                Route::delete('sessions', [WlpAssistantController::class, 'destroy']);
            });

            // Mail configuration: a partner's providers, each verified with a
            // code, and the one platform-wide fallback the host partner keeps.
            Route::prefix('mail-configurations')->group(function () {
                // Host partner's own account only - checked in the controller.
                Route::prefix('fallback')->controller(FallbackMailConfigurationController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::post('/{mailConfiguration}/default', 'makeDefault')->whereNumber('mailConfiguration');
                    Route::post('/{mailConfiguration}/send-code', 'sendCode')->whereNumber('mailConfiguration');
                    Route::post('/{mailConfiguration}/verify', 'verify')->whereNumber('mailConfiguration')->middleware('throttle:20,1');
                });

                Route::middleware('permission:update email configuration settings')
                    ->controller(MailConfigurationController::class)
                    ->group(function () {
                        Route::get('/', 'index');
                        Route::post('/', 'store');
                        Route::delete('/{mailConfiguration}', 'destroy')->whereNumber('mailConfiguration');
                        Route::post('/{mailConfiguration}/default', 'makeDefault')->whereNumber('mailConfiguration');
                        Route::post('/{mailConfiguration}/send-code', 'sendCode')->whereNumber('mailConfiguration');
                        Route::post('/{mailConfiguration}/verify', 'verify')->whereNumber('mailConfiguration')->middleware('throttle:20,1');
                    });
            });

            // SMS Configuration (host and white-label partners)
            Route::prefix('sms-configuration')
                ->middleware('permission:update sms configuration settings')
                ->group(function () {
                    Route::get('/', [SmsConfigurationController::class, 'index']);
                    Route::post('/', [SmsConfigurationController::class, 'store']);
                    Route::post('/test', [SmsConfigurationController::class, 'test'])->middleware('throttle:5,1');
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
                Route::get('/{id}', [CommonResourceController::class, 'show']);
                Route::get('/download/{id}', [CommonResourceController::class, 'download']);
            });

            // 📝 Embedded Form Management (Internal / Authenticated)
            Route::prefix('embed-forms')->group(function () {
                // The same four permissions the admin panel gates these screens
                // on, so a role without them is refused here too and not only
                // hidden from the menu.
                Route::get('config', [EmbedFormController::class, 'getConfig'])
                    ->middleware('permission:view configuration');
                Route::put('config', [EmbedFormController::class, 'updateConfig'])
                    ->middleware('permission:update configuration');
                Route::post('config/generate-key', [EmbedFormController::class, 'regenerateKey'])
                    ->middleware('permission:update configuration');

                Route::post('submissions/fetch', [EmbedFormController::class, 'fetchSubmissions'])
                    ->middleware('permission:view application');
                Route::get('submissions/{id}', [EmbedFormController::class, 'showSubmission'])
                    ->whereNumber('id')
                    ->middleware('permission:view application');
                // The panel offers the status dropdown to anyone who can open an
                // application, so this follows the same permission.
                Route::put('submissions/{id}/status', [EmbedFormController::class, 'updateStatus'])
                    ->whereNumber('id')
                    ->middleware('permission:view application');
                Route::get('submissions/{id}/conversion-preview', [EmbedFormController::class, 'conversionPreview'])
                    ->whereNumber('id')
                    ->middleware('permission:convert application');
                Route::post('submissions/{id}/convert-to-borrower', [EmbedFormController::class, 'convertToBorrower'])
                    ->whereNumber('id')
                    ->middleware('permission:convert application');

                Route::get('attachments/{id}/download', [EmbedFormController::class, 'downloadAttachment'])
                    ->whereNumber('id')
                    ->middleware('permission:view application');
            });


                    // V1 Reminder routes
                Route::prefix('reminders')->group(function () {
                    Route::post('/fetch-reminders', [ReminderController::class, 'index']);
                    Route::get('/due', [ReminderController::class, 'getDue']);
                    Route::post('/bulk-dismiss', [ReminderController::class, 'bulkDismiss']);
                    Route::post('/dismiss-all', [ReminderController::class, 'dismissAll']);
                    Route::post('/{id}/dismiss', [ReminderController::class, 'dismiss']);
                    Route::post('/{id}/snooze', [ReminderController::class, 'snooze']);
                    Route::get('/{id}/attachments/download', [ReminderController::class, 'downloadAttachment']);
                });

                // In-app notification inbox (bell + notifications page)
                Route::prefix('notifications')->group(function () {
                    Route::get('/', [NotificationInboxController::class, 'index']);
                    Route::get('/unread-count', [NotificationInboxController::class, 'unreadCount']);
                    Route::post('/read-all', [NotificationInboxController::class, 'markAllRead']);
                    Route::post('/{id}/read', [NotificationInboxController::class, 'markRead']);
                    Route::delete('/{id}', [NotificationInboxController::class, 'destroy']);
                });


        });

        Route::post('process-payment', [UnifiedPaymentController::class, 'processPayment']);
        Route::post('process-payment-for-users', [UnifiedPaymentController::class, 'processPayment']);

        // Unified Multi-Gateway Payment Routes
        Route::prefix('payment')->group(function () {
            Route::post('process', [UnifiedPaymentController::class, 'processPayment']);
            Route::get('providers', [UnifiedPaymentController::class, 'getProviders']);
            Route::get('get-payment-methods', [UnifiedPaymentController::class, 'getPartnerPaymentMethods']);
        });

        // V1 Notification routes - partner portal only (Settings > Email test);
        // it mails caller-chosen text to a caller-chosen address.
        Route::prefix('notifications')->middleware('partner-portal')->group(function () {
            Route::post('/test-email', [V1NotificationController::class, 'sendTestEmail']);
        });
    });
});
