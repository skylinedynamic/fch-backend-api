<?php

namespace App\Services\FCH\Partner;

use App\Models\Subscription\SubscriptionPhase;
use App\Models\User\User;
use App\Models\Common\Role;
use Illuminate\Support\Str;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Http\Request;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\ActivityLog\ActivityLog;
use Spatie\Permission\PermissionRegistrar;
use App\Mail\Partner\PartnerPaymentLinkMail;
use App\Mail\Partner\PartnerMigrationMail;
use App\Mail\Trial\PartnerTrialMail;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Partner\PartnerAccountUpdateMail;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Http\Resources\FCH\Borrower\BorrowerPartnerResource;

class PartnerService
{
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService,
        protected \App\Services\FCH\Common\ActivityLog\ActivityLogService $activityLogService,
    ) {
    }

    /**
     * Send payment link email using partner's own email config.
     */
    private function sendPaymentLinkEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            Mail::to($mailData['user']->email)->send(new PartnerPaymentLinkMail($mailData, $hostPartnerId));
        } catch (\Exception $e) {
            Log::error('Partner payment link email failed', [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send migration notification email using partner's own email config.
     */
    private function sendMigrationEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            Mail::to($mailData['user']->email)->send(new PartnerMigrationMail($mailData, $hostPartnerId));
        } catch (\Exception $e) {
            Log::error('Partner migration email failed', [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send free trial welcome email using partner's own email config.
     */
    private function sendTrialWelcomeEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            Mail::to($mailData['user']->email)->send(new PartnerTrialMail($mailData, $hostPartnerId));
        } catch (\Exception $e) {
            Log::error('Partner trial welcome email failed', [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send account update email using partner's own email config.
     */
    private function sendAccountUpdateEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            Mail::to($mailData['user']->email)->send(new PartnerAccountUpdateMail($mailData, $hostPartnerId));

        } catch (\Exception $e) {
            Log::error('Partner account update email failed', [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Field mapping for user table fields.
     */
    private array $userFieldMapping = [
        'full_name' => 'name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'city' => 'city',
        'state' => 'state',
        'postal_code' => 'postal_code',
        'country' => 'country',
        'address_line_1' => 'address_line_1',
        'address_line_2' => 'address_line_2',
    ];

    /**
     * Field mapping for partner table fields.
     */
    private array $partnerFieldMapping = [
        'full_name' => 'full_name',
        'company_name' => 'company_name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'contact_person' => 'contact_person',
        'contact_person_email' => 'contact_person_email',
        'contact_person_phone_number' => 'contact_person_phone_number',
        'contact_person_relation' => 'contact_person_relation',
        'partner_type' => 'partner_type',
        'status' => 'status',
        'state' => 'state',
        'city' => 'city',
        'address_line_1' => 'address_line_1',
        'postal_code' => 'postal_code',
        'address_line_2' => 'address_line_2',
        'country' => 'country',
        'country_code' => 'country_code',
        'joining_date' => 'joining_date',
        'company_logo' => 'company_logo',
        'tax_id' => 'tax_id',
        'website' => 'website',
        'partner_image' => 'partner_image',
        'partner_domain' => 'partner_domain',
        'borrower_domain' => 'borrower_domain',
        'assign_to' => 'assign_to', // Assigned agent/user
        'is_free_trail' => 'is_free_trail',
        'free_trail_end_date' => 'free_trail_end_date',
    ];

    /**
     * Resolve a partner subscription plan by partner_type and enrollment_type.
     */
    private function resolvePartnerPlan(?string $partnerType, ?string $enrollmentType, ?int $planId = null): ?SubscriptionPlan
    {
        if ($planId) {
            $plan = SubscriptionPlan::find($planId);
            if ($plan) {
                return $plan;
            }
        }

        if (!$partnerType || !$enrollmentType) {
            return null;
        }

        $isInstallment = strtolower(trim((string) $enrollmentType)) === SubscriptionPlan::INSTALLMENT_PLAN
            || Str::slug($enrollmentType) === SubscriptionPlan::INSTALLMENT_PLAN
            || Str::contains(strtolower((string) $enrollmentType), SubscriptionPlan::INSTALLMENT_PLAN)
            || Str::contains(strtolower((string) $enrollmentType), 'payment-plan');

        $targetSlug = $isInstallment ? SubscriptionPlan::INSTALLMENT_PLAN : SubscriptionPlan::CERTIFIED_ENROLMENT_TYPE_ONE_TIME;

        $plan = SubscriptionPlan::where('partner_type', $partnerType)
            ->where(function ($q) use ($enrollmentType, $targetSlug) {
                $q->where('enrollment_type', $enrollmentType)
                    ->orWhere('enrollment_type_slug', Str::slug($enrollmentType))
                    ->orWhere('enrollment_type_slug', $targetSlug);
            })
            ->first();

        if (!$plan) {
            $plan = SubscriptionPlan::where('partner_type_slug', Str::slug($partnerType))
                ->where(function ($q) use ($enrollmentType, $targetSlug) {
                    $q->where('enrollment_type', $enrollmentType)
                        ->orWhere('enrollment_type_slug', Str::slug($enrollmentType))
                        ->orWhere('enrollment_type_slug', $targetSlug);
                })
                ->first();
        }

        return $plan;
    }

    /**
     * Calculate partner subscription amounts, applying custom overrides where supplied.
     * Handles One Time Payment and Installment enrollment data formats.
     * Returns ['enrollment', 'monthly', 'total', 'payable_amount', 'initial_invoice_amount', 'down_payment', 'installment_months', 'installment_monthly_amount', 'installment_remaining_balance', 'installment_type', 'isCustom'].
     */
    private function calculatePartnerSubscriptionAmounts(?SubscriptionPlan $plan, array $data, ?Subscription $existingSubscription = null): array
    {
        $enrollmentType = $data['enrollment_type'] ?? $existingSubscription?->enrollment_type ?? $plan?->enrollment_type ?? '';

        $isInstallment = strtolower((string) $enrollmentType) == SubscriptionPlan::INSTALLMENT_PLAN;

        // 1. Total Enrollment Amount
        $enrollment = null;
        if (isset($data['enrollment_amount']) && $data['enrollment_amount'] !== '') {
            $enrollment = (float) $data['enrollment_amount'];
        } elseif ($existingSubscription) {
            $enrollment = (float) $existingSubscription->payable_amount;
        } else {
            $enrollment = (float) ($plan->total_amount ?? $plan->payment_amount ?? 0);
        }

        // 2. Monthly Platform Fee
        $monthly = null;
        if (isset($data['monthly_platform_fee']) && $data['monthly_platform_fee'] !== '') {
            $monthly = (float) $data['monthly_platform_fee'];
        } elseif ($existingSubscription) {
            $monthly = (float) $existingSubscription->monthly_platform_fee;
        } else {
            $monthly = (float) ($plan->platform_fee ?? 0);
        }

        // 3. Installment-specific or One Time Payment calculations
        $downPayment = 0.0;
        $installmentMonths = (int) ($data['installment_months'] ?? $plan->total_installment_count_upto ?? 1);
        $monthlyInstallmentAmount = 0.0;
        $remainingBalance = 0.0;

        if ($isInstallment) {
            if (isset($data['installment_down_payment']) && $data['installment_down_payment'] !== '') {
                $downPayment = (float) $data['installment_down_payment'];
            }
            if (isset($data['installment_monthly_amount']) && $data['installment_monthly_amount'] !== '') {
                $monthlyInstallmentAmount = (float) $data['installment_monthly_amount'];
            }
            if (isset($data['installment_remaining_balance']) && $data['installment_remaining_balance'] !== '') {
                $remainingBalance = (float) $data['installment_remaining_balance'];
            } else {
                $remainingBalance = max(0, $enrollment - $downPayment);
            }

            if ($monthlyInstallmentAmount <= 0 && $installmentMonths > 0) {
                $monthlyInstallmentAmount = $remainingBalance > 0 ? ($remainingBalance / $installmentMonths) : 0;
            }

            $payableAmount = $enrollment;
            $total = $remainingBalance;
            $initialInvoiceAmount = $downPayment > 0 ? $downPayment : $payableAmount;
            $installmentType = 1;
        } else {
            $downPayment = 0.0;
            $payableAmount = $enrollment;
            $total = $enrollment;
            $initialInvoiceAmount = $enrollment;
            $installmentType = 0;
        }

        $isCustom = false;
        if ($plan) {
            $isCustom = (abs($enrollment - (float) ($plan->total_amount ?? $plan->payment_amount ?? 0)) > 0.01)
                || (abs($monthly - (float) ($plan->platform_fee ?? 0)) > 0.01);
        }

        return [
            'enrollment' => $enrollment,
            'monthly' => $monthly,
            'total' => $total,
            'payable_amount' => $payableAmount,
            'initial_invoice_amount' => $initialInvoiceAmount,
            'down_payment' => $downPayment,
            'installment_months' => $installmentMonths,
            'installment_monthly_amount' => $monthlyInstallmentAmount,
            'installment_remaining_balance' => $remainingBalance,
            'installment_type' => $installmentType,
            'isCustom' => $isCustom,
        ];
    }

    /**
     * Create a partner enrollment invoice, log it, prepare mail data, and dispatch the email.
     */
    private function createPartnerInvoiceAndSendEmail(
        Partner $partner,
        ?object $user,
        float $enrollmentAmount,
        ?string $enrollmentType,
        string $paymentLink,
        array $businessInfo,
        ?array $extraMailData,
        int $senderPartnerId,
        bool $isMigration = false,
        ?int $subscriptionId = null
    ): void {
        $invoiceType = Invoice::INVOICE_TYPE_ENROLLMENT;

        $invoice = $this->invoiceService->create([
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'subscription_id' => $subscriptionId,
            'customer_name' => $partner->full_name,
            'email' => $partner->email,
            'country' => $partner->country,
            'payment_amount' => $enrollmentAmount,
            'is_partner_paying' => false,
            'status' => Invoice::STATUS_UNPAID,
            'type' => $invoiceType,
        ]);

        $this->activityLogService->logModelEvent($invoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);

        $mailData = array_merge([
            'partner_id' => $partner->id,
            'user' => $user,
            'payment_link' => $paymentLink,
            'amount' => $invoice->payment_amount,
            'enrollment_type' => $enrollmentType ?? 'Enrollment Fee',
            'business_info' => $businessInfo,
        ], $extraMailData ?? []);

        if ($isMigration) {
            $this->sendMigrationEmail($mailData, $senderPartnerId);
        } else {
            $this->sendPaymentLinkEmail($mailData, $senderPartnerId);
        }
    }

    /**
     * Build the business_info array from the host partner (or app config fallback).
     */
    private function getHostPartnerBusinessInfo(?Partner $hostPartner): array
    {
        return [
            'company_name' => $hostPartner->company_name ?? config('app.name'),
            'email' => $hostPartner->email ?? config('mail.from.address'),
            'phone_number' => $hostPartner->phone_number ?? config('app.phone_number', ''),
            'address_line_1' => $hostPartner->address_line_1 ?? config('app.address', ''),
            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
        ];
    }

    /**
     * Create a new partner.
     */
    public function store(array $data): array
    {
        DB::beginTransaction();
        try {
            // Create or update user
            $userResult = $this->createOrUpdateUser($data);
            $user = $userResult['user'];
            $plainPassword = $userResult['plain_password'];
            $isNew = $userResult['is_new'];

            $partnerData = $this->mapPartnerData($data);
            $isFreeTrial = isset($data['is_free_trail']) && ($data['is_free_trail'] == 1 || $data['is_free_trail'] === true || $data['is_free_trail'] === 'true');
            $partnerData['status'] = $isFreeTrial ? Partner::STATUS_ACTIVE : Partner::STATUS_ONBOARDING;

            $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
            // Generate subdomains if not provided
            if (empty($partnerData['partner_domain']) && !empty($partnerData['company_name'])) {
                $subdomain = $this->generateSubdomain($partnerData['company_name']);

                // Check against restricted domains OR existing subdomains
                if (in_array($subdomain, Partner::RESTRICTED_DOMAINS) || Partner::where('partner_domain', $subdomain . '.' . $baseUrl)->exists()) {
                    $subdomain .= '-' . $user->id;
                }

                $partnerData['partner_domain'] = $subdomain . '.' . $baseUrl;
                $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
            } else {
                // Append base url to provided subdomain
                $partnerData['partner_domain'] = $partnerData['partner_domain'] . '.' . $baseUrl;
                $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
            }

            $partner = Partner::create($partnerData);

            // Log Partner Creation
            $this->activityLogService->logModelEvent($partner, ActivityLog::LOG_TYPE_PARTNER_CREATE);
            // Handle partner_image upload
            if (isset($partnerData['partner_image'])) {
                $imageName = ltrim($partnerData['partner_image'], '/');
                $partnerData['partner_image'] = 'partners/' . $partner->id . '/' . $imageName;
                $partner->update(['partner_image' => $partnerData['partner_image']]);
            }

            // Update user with partner link
            $user->partner_id = $partner->id;
            $user->entity_type = Partner::class;
            $user->entity_id = $partner->id;
            $user->save();

            $this->createPartnerRoles($partner->id, $user);

            if (isset($data['is_free_trail']) && $data['is_free_trail']) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailData = [
                    'partner_id' => $partner->id,
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'trial_end_date' => $data['free_trail_end_date'] ?? null,
                    'business_info' => $this->getHostPartnerBusinessInfo($hostPartner),
                ];

                $authUser = Auth::user();
                $senderPartnerId = $authUser ? $authUser->partner_id : ($hostPartner ? $hostPartner->id : null);
                $this->sendTrialWelcomeEmail($mailData, $senderPartnerId);

                $trialEndDate = $data['free_trail_end_date'] ?? null;
                $this->activityLogService->logModelEvent(
                    $partner,
                    ActivityLog::LOG_TYPE_PARTNER_FREE_TRIAL,
                    [],
                    null,
                    __('partner.free_trial_created_log', ['date' => $trialEndDate ?? 'N/A'])
                );
            } else {
                $subscription = null;
                $partnerType = $data['partner_type'] ?? null;
                $enrollmentType = $data['enrollment_type'] ?? null;
                $plan = $this->resolvePartnerPlan($partnerType, $enrollmentType, $data['plan_id'] ?? null);
                $amounts = $this->calculatePartnerSubscriptionAmounts($plan, $data);

                if ($enrollmentType) {
                    $subscription = $this->subscriptionService->startSubscription($partner, $plan, [
                        'subscription_plan_id' => $plan->id ?? null,
                        'partner_type' => $partnerType,
                        'enrollment_type' => $enrollmentType,
                        'installment_type' => $amounts['installment_type'],
                        'installment_count' => $amounts['installment_months'],
                        'monthly_platform_fee' => $amounts['monthly'],
                        'payable_amount' => $amounts['payable_amount'],
                        'total_subscription_amount' => $amounts['total'],
                        'subscription_amount_per_month' => $amounts['installment_type'] == 1 ? $amounts['installment_monthly_amount'] : $amounts['monthly'],
                        'down_payment' => $amounts['down_payment'],
                        'is_custom' => $amounts['isCustom'],
                    ]);
                }

                $authUser = Auth::user();
                $senderPartnerId = $authUser ? $authUser->partner_id : Partner::where('is_host', 1)->value('id');

                if ($enrollmentType || $amounts['initial_invoice_amount'] > 0) {
                    $paymentLink = concatUrl(getPartnerDomain($partner->id), 'invoices', config('app.frontend_partner_url'));
                    $this->createPartnerInvoiceAndSendEmail(
                        $partner,
                        $user,
                        $amounts['initial_invoice_amount'],
                        $enrollmentType,
                        $paymentLink,
                        [
                            'company_name' => config('app.name'),
                            'email' => config('mail.from.address'),
                            'phone_number' => config('app.phone_number', ''),
                            'address_line_1' => config('app.address', ''),
                            'company_logo' => 'logo.png',
                        ],
                        ['plain_password' => $plainPassword],
                        $senderPartnerId,
                        false,
                        $subscription->id ?? null
                    );
                }
            }

            DB::commit();

            return [
                'partner' => $partner->fresh(),
                'user' => $user->fresh(),
                'plain_password' => $plainPassword,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Partner creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create default roles (Admin and Borrower) for a new partner.
     * Assigns the Admin role to the partner's user.
     * Permissions are filtered based on partner's is_host status.
     */
    private function createPartnerRoles(int $partnerId, User $user): void
    {
        try {
            // Clear cached permissions before creating roles
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Get partner to check is_host status
            $partner = Partner::find($partnerId);

            if (!$partner) {
                throw new \Exception("Partner with ID {$partnerId} not found");
            }

            if ($partner->partner_type == Partner::PARTNER_TYPE_CERTIFIED) {

                $allowedPermissions = Permission::where('guard_name', 'api')
                    ->where('is_host', 0)
                    ->where('is_certified', 1)
                    ->get();
            } else {

                $allowedPermissions = Permission::where('guard_name', 'api')
                    ->where('is_host', 0)
                    ->get();
            }

            if ($allowedPermissions->isEmpty()) {
                Log::warning('No API permissions found. Roles will be created without permissions.');
            }

            // Create or update Admin role with filtered permissions
            // Using updateOrCreate to avoid duplicate role name errors (partner-wise roles)
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partnerId],
                [
                    'name' => 'Admin',
                    'guard_name' => 'api',
                    'partner_id' => $partnerId,
                    'code' => 'admin',
                    'is_static' => true,
                ]
            );

            // Assign Admin role to the partner's user
            $user->assignRole($adminRole);

            // Also sync permissions directly to user (like EmployeeService)
            if ($allowedPermissions->isNotEmpty()) {
                $adminRole->syncPermissions($allowedPermissions);
                $user->syncPermissions($allowedPermissions);
            }

            // Clear cached permissions after role creation
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Exception $e) {
            Log::error('Failed to create partner roles', [
                'partner_id' => $partnerId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing partner.
     */
    public function update(Partner $partner, array $data): Partner
    {
        DB::beginTransaction();
        try {
            // Handle partner_image upload

            if (isset($data['partner_image'])) {

                $imageName = ltrim($data['partner_image'], '/');

                $data['partner_image'] = 'partners/' . $partner->id . '/' . $imageName;
            }

            // Update user
            // Assuming the partner has a linked user via polymorphic relation or we find by email
            $user = $partner->partnerUser ?? User::where('email', $data['email'] ?? '')->first();
            $wasFreeTrial = (bool) $partner->is_free_trail;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($user) {
                $userResult = $this->createOrUpdateUser($data, $user);
                $updatedUser = $userResult['user'];
                $plainPassword = $userResult['plain_password'];
                $isNew = $userResult['is_new'];

                if ($plainPassword) {
                    $mailData = [
                        'partner_id' => $partner->id,
                        'user' => $updatedUser,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => config('app.name'),
                            'email' => config('mail.from.address'),
                            'phone_number' => config('app.phone_number', ''),
                            'address_line_1' => config('app.address', ''),
                            'company_logo' => 'logo.png',
                        ],
                        'is_update' => true,
                    ];
                    $authUser = Auth::user();
                    $senderPartnerId = $authUser ? $authUser->partner_id : Partner::where('is_host', 1)->value('id');
                    $this->sendAccountUpdateEmail($mailData, $senderPartnerId);
                }
            }

            // Update partner
            $oldStatus = $partner->status;
            $partnerData = $this->mapPartnerData($data);
            if ($wasFreeTrial && !$isFreeTrialInput) {
                $partnerData['status'] = Partner::STATUS_ONBOARDING;
            }

            if ($partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED && !empty($partnerData['partner_domain'])) {
                $subdomain = trim($partnerData['partner_domain']);
                $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
                if (!str_ends_with($subdomain, '.' . $baseUrl)) {
                    $partnerData['partner_domain'] = $subdomain . '.' . $baseUrl;
                    $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
                }
            }

            $partner->update($partnerData);

            // Sync user status if partner status changed
            if ($partner->status !== $oldStatus) {
                if (in_array($partner->status, [Partner::STATUS_SUSPENDED, Partner::STATUS_INACTIVE])) {
                    $this->restrictPartnerUsers($partner);
                } elseif ($partner->status === Partner::STATUS_ACTIVE) {
                    $this->reactivatePartnerUsers($partner);
                }
            }

            $changes = $partner->getChanges();
            unset($changes['updated_at']);

            if (!empty($changes)) {
                $extraProps = [];
                if (isset($changes['email'])) {
                    $extraProps['new_email'] = $changes['email'];
                }

                $isEmailOnly = (count($changes) === 1 && isset($changes['email']));
                $contactFields = ['email', 'phone_number', 'contact_person', 'contact_person_email', 'contact_person_phone_number', 'contact_person_relation'];

                $changedKeys = array_keys($changes);
                $isContactOnly = count(array_diff($changedKeys, $contactFields)) === 0;

                $logType = ActivityLog::LOG_TYPE_PARTNER_UPDATE;
                if ($isEmailOnly) {
                    $logType = ActivityLog::LOG_TYPE_EMAIL_UPDATE;
                } elseif ($isContactOnly) {
                    $logType = ActivityLog::LOG_TYPE_PARTNER_CONTACT_UPDATE;
                }

                $changedFields = [];
                foreach ($changes as $field => $newValue) {
                    $name = $field === 'assign_to' ? 'Assigned User' : ucwords(str_replace('_', ' ', $field));

                    if ($field === 'assign_to') {
                        $changedFields[] = "{$name} was changed";
                    } elseif ($field === 'full_name') {
                        $val = (string) $newValue;
                        $val = $val === '' ? 'empty' : $val;
                        $changedFields[] = "Name was updated to {$val}";
                    } else {
                        $val = (string) $newValue;
                        $val = $val === '' ? 'empty' : $val;
                        $changedFields[] = "{$name} was updated to {$val}";
                    }
                }
                $fieldsList = implode(', ', $changedFields);

                $customDescription = $isEmailOnly ? null : "{$fieldsList}.";

                $this->activityLogService->logModelEvent(
                    $partner,
                    $logType,
                    $extraProps,
                    null,
                    $customDescription
                );
            }

            // Update subscription if custom amounts or enrollment details are provided
            $subscription = $partner->subscription;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($wasFreeTrial && !$isFreeTrialInput) {
                // Transition off free trial
                $subscription = null;
                $partnerType = $data['partner_type'] ?? $partner->partner_type;
                $enrollmentType = $data['enrollment_type'] ?? null;
                $plan = $this->resolvePartnerPlan($partnerType, $enrollmentType, $data['plan_id'] ?? null);
                $amounts = $this->calculatePartnerSubscriptionAmounts($plan, $data);

                if ($enrollmentType) {
                    $subscription = $this->subscriptionService->startSubscription($partner, $plan, [
                        'subscription_plan_id' => $plan->id ?? null,
                        'partner_type' => $plan->partner_type ?? $partnerType,
                        'enrollment_type' => $enrollmentType,
                        'installment_type' => $amounts['installment_type'],
                        'installment_count' => $amounts['installment_months'],
                        'monthly_platform_fee' => $amounts['monthly'],
                        'payable_amount' => $amounts['payable_amount'],
                        'total_subscription_amount' => $amounts['total'],
                        'subscription_amount_per_month' => $amounts['installment_type'] == 1 ? $amounts['installment_monthly_amount'] : $amounts['monthly'],
                        'down_payment' => $amounts['down_payment'],
                        'is_custom' => $amounts['isCustom'],
                    ]);
                }

                $authUser = Auth::user();
                $senderPartnerId = $authUser ? $authUser->partner_id : Partner::where('is_host', 1)->value('id');

                if ($enrollmentType || $amounts['initial_invoice_amount'] > 0) {
                    $hostPartner = Partner::where('is_host', 1)->first();
                    $paymentLink = concatUrl(getPartnerDomain($partner->id), 'invoices', config('app.frontend_partner_url'));
                    $this->createPartnerInvoiceAndSendEmail(
                        $partner,
                        $user,
                        $amounts['initial_invoice_amount'],
                        $enrollmentType,
                        $paymentLink,
                        $this->getHostPartnerBusinessInfo($hostPartner),
                        null,
                        $senderPartnerId,
                        isMigration: true,
                        subscriptionId: $subscription->id ?? null
                    );
                }
            }

            $user->update(['is_refresh' => true]);
            DB::commit();

            return $partner->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Partner update failed', [
                'partner_id' => $partner->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find a partner by ID.
     */
    public function findById(int $id): ?Partner
    {
        return Partner::with('partnerUser')->find($id);
    }

    /**
     * Get partner details by ID with all relationships.
     */
    public function getDetails(int $id): ?Partner
    {
        return Partner::with(['assignedUser', 'partnerUser', 'subscription'])
            ->find($id);
    }

    /**
     * Create or update user based on email.
     */
    private function createOrUpdateUser(array $data, ?User $existingUser = null): array
    {
        $userData = $this->mapUserData($data);
        $plainPassword = null;

        if ($existingUser) {

            $plainPassword = null;

            if (isset($userData['email']) && $existingUser->email !== $userData['email']) {
                $plainPassword = bin2hex(random_bytes(4));
                $userData['password'] = bcrypt($plainPassword);
            }

            $userData['is_refresh'] = true;
            $existingUser->update($userData);

            return ['user' => $existingUser, 'plain_password' => $plainPassword, 'is_new' => false];
        }

        // Check if user exists by email
        // nomal data update
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $userData['is_refresh'] = true;
            $user->update($userData);

            return ['user' => $user, 'plain_password' => null, 'is_new' => false];
        }

        // Create new user
        $plainPassword = bin2hex(random_bytes(4));
        $userData['password'] = bcrypt($plainPassword);
        $userData['user_type'] = User::PARTNER;

        $user = User::create($userData);

        return ['user' => $user, 'plain_password' => $plainPassword, 'is_new' => true];
    }

    /**
     * Map request data to user model attributes.
     */
    private function mapUserData(array $data): array
    {
        $userData = [];

        foreach ($this->userFieldMapping as $requestField => $userField) {
            if (array_key_exists($requestField, $data)) {
                $userData[$userField] = $data[$requestField];
            }
        }

        return $userData;
    }

    /**
     * List partners with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with([
                'assignedUser' => function ($userQuery) {
                    $userQuery->select('id', 'name');
                },
                'partnerUser' => function ($userQuery) {
                    $userQuery->select('id', 'entity_type', 'entity_id');
                },
                'subscription'
            ]);

        // Filter based on authenticated user's host status
        // if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
        //     // If logged-in user is a host, show only non-host partners
        //     $query->where('is_host', '!=', 1);
        // } else {
        //     // Otherwise, show only host partners
        //     $query->where('is_host', 1);
        // }

        // 🔍 Filter by partner_id (exact match)
        if (!empty($filters['partner_id'])) {
            $query->where('partners.id', $filters['partner_id']);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if (!empty($filters['assigned_user_id'])) {
            $query->where('partners.assign_to', $filters['assigned_user_id']);
        }

        // 🔍 Filter by is_free_trail (directly on partners table)
        if (isset($filters['is_free_trail']) && $filters['is_free_trail'] !== '') {
            $query->where('partners.is_free_trail', $filters['is_free_trail']);
        }

        // 🔍 Search filter (uses scopeSearch from Partner model)
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;

        // Handle special field aliases
        if ($sortField === 'assigned_user_name') {
            // Join users table to sort by name
            $query->leftJoin('users as assigned_users', 'partners.assign_to', '=', 'assigned_users.id')
                ->orderBy('assigned_users.name', $sortOrder)
                ->select('partners.*'); // Ensure we still only select partner fields

            $skipSorting = true;
        }

        // 🔍 Filter by assigned_user_name (related user name)
        if (isset($filters['filters']['assigned_user_name'])) {
            $assignedUserFilter = $filters['filters']['assigned_user_name'];
            // Check if it has constraints
            if (isset($assignedUserFilter['constraints']) && is_array($assignedUserFilter['constraints'])) {
                $hasActiveConstraint = false;
                foreach ($assignedUserFilter['constraints'] as $c) {
                    if (!empty($c['value'])) {
                        $hasActiveConstraint = true;
                        break;
                    }
                }

                if ($hasActiveConstraint) {
                    $query->whereHas('assignedUser', function ($q) use ($assignedUserFilter) {
                        $q->where(function ($subQ) use ($assignedUserFilter) {
                            foreach ($assignedUserFilter['constraints'] as $constraint) {
                                $value = $constraint['value'] ?? null;
                                $matchMode = $constraint['matchMode'] ?? 'contains';

                                if ($value) {
                                    // Apply logic based on matchMode
                                    $likeContains = "%{$value}%";
                                    $likeStarts = "{$value}%";
                                    $likeEnds = "%{$value}";

                                    if ($matchMode === 'contains') {
                                        $subQ->where('name', 'like', $likeContains);
                                    } elseif ($matchMode === 'startsWith') {
                                        $subQ->where('name', 'like', $likeStarts);
                                    } elseif ($matchMode === 'endsWith') {
                                        $subQ->where('name', 'like', $likeEnds);
                                    } else {
                                        $subQ->where('name', '=', $value);
                                    }
                                }
                            }
                        });
                    });
                }
            }
            // Unset to prevent QueryFilter from processing it incorrectly
            unset($filters['filters']['assigned_user_name']);
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => $skipSorting,
            ]
        );

        // Calculate statistics before pagination
        $statistics = [
            'total_partners' => (clone $query)->count(),
            'active_partners' => (clone $query)->where('status', Partner::STATUS_ACTIVE)->count(),
            'inactive_partners' => (clone $query)->whereIn('status', [Partner::STATUS_INACTIVE, Partner::STATUS_SUSPENDED])->count(),
            'new_this_month' => (clone $query)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PartnerResource::class,
            __('partner.success.retrieved'),
            200,
            ['statistics' => $statistics]
        );
    }

    /**
     * Get all partners with their user information (legacy method with statistics).
     */
    public function getAllPartners(Request $request)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with([
                'assignedUser' => function ($userQuery) {
                    $userQuery->select('id', 'name');
                }
            ])
            ->select(
                'partners.id',
                'partners.company_name',
                'partners.full_name',
                'partners.contact_person',
                'partners.contact_person_email',
                'partners.contact_person_phone_number',
                'partners.email',
                'partners.phone_number',
                'partners.partner_type',
                'partners.is_host',
                'partners.assign_to',
                'partners.created_at'
            );

        // Filter based on authenticated user's host status
        $hostFilter = null;
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If logged-in user is a host, show only non-host partners
            $query->where('is_host', '!=', 1);
            $hostFilter = ['is_host', '!=', 1];
        } else {
            // Otherwise, show only host partners
            $query->where('is_host', 1);
            $hostFilter = ['is_host', '=', 1];
        }

        // 🔍 Filter by partner_id (exact match)
        if ($partnerId = $request->get('partner_id')) {
            $query->where('partners.id', $partnerId);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if ($assignedUserId = $request->get('assigned_user_id')) {
            $query->where('partners.assign_to', $assignedUserId);
        }

        // 🔍 Search filter (searches all partner and user fields)
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%") // Changed from email
                    ->orWhere('phone_number', 'LIKE', "%{$search}%") // Changed from phone_number
                    ->orWhere('full_name', 'LIKE', "%{$search}%") // Changed from owner_name
                    ->orWhere('contact_person', 'LIKE', "%{$search}%")
                    ->orWhere('partner_type', 'LIKE', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                        $userQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        // 📄 Pagination (default 15 per page)
        $partners = $query->orderByDesc('partners.id')->paginate($request->get('per_page', 15));

        // Transform to the required flat structure
        $data = $partners->map(function ($partner) {
            $assignedUser = $partner->assignedUser;

            return [
                'id' => $partner->id,
                'assigned_user_id' => $assignedUser->id ?? null,
                'assigned_user_name' => $assignedUser->name ?? null,
                'full_name' => $partner->full_name,
                'company_name' => $partner->company_name,
                'email' => $partner->email,
                'phone_number' => $partner->phone_number,
                'contact_person' => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'partner_type' => $partner->partner_type,
                'status' => $partner->status,
                'joining_date' => $partner->joining_date,
            ];
        })->all();

        // Return data with pagination meta and statistics
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $partners->currentPage(),
                'from' => $partners->firstItem(),
                'to' => $partners->lastItem(),
                'per_page' => $partners->perPage(),
                'total' => $partners->total(),
                'last_page' => $partners->lastPage(),
                'next_page_url' => $partners->nextPageUrl(),
                'prev_page_url' => $partners->previousPageUrl(),
                'links' => $partners->linkCollection(),
            ],

        ];
    }


    /**
     * Map request data to partner model attributes.
     */
    private function mapPartnerData(array $data): array
    {
        $partnerData = [];

        foreach ($this->partnerFieldMapping as $requestField => $partnerField) {

            if (array_key_exists($requestField, $data)) {
                $value = $data[$requestField];
                if ($partnerField === 'joining_date' && !empty($value)) {
                    $value = normalizeDate($value);
                }
                $partnerData[$partnerField] = $value;
            }
        }

        return $partnerData;
    }

    /**
     * Get Borrowers list.
     */
    public function getBorrowers(array $filters)
    {

        $query = Borrower::query()->with('partner');

        // Filter by partner_id
        if (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';

        // Handle full_name sorting by using first_name as proxy
        if ($sortField === 'full_name') {
            $sortField = 'first_name';
        }

        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Apply QueryFilter
        // Search across multiple fields
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('business_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('business_start_date', 'LIKE', "%{$search}%")
                    // Search concatenated full_name (first_name + last_name)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'company_name' => 'business_name',
                ],
                'concat_fields' => [
                    'full_name' => ['first_name', 'last_name'],
                ],
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerPartnerResource::class,
            __('partner.borrowers_retrieved_data')
        );
    }

    /**
     * Delete Partner
     */
    public function delete(array $ids)
    {
        try {
            DB::beginTransaction();
            $partners = Partner::whereIn('id', $ids)->get();
            foreach ($partners as $partner) {
                if ($partner->partner_image) {
                    $this->deleteFile($partner->partner_image, 'public');
                }
                $partner->safeDeleteWithRelations();
            }
            DB::commit();

            return ApiResponse::success([], __('partner.success.deleted'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Partner deletion failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Check if multiple partners are deletable
     */
    public function checkDeletable(array $ids): array
    {
        $partners = Partner::whereIn('id', $ids)->get();
        $results = [];

        foreach ($partners as $partner) {
            $is_deletable = $partner->isDeletable();
            $results[] = [
                'id' => $partner->id,
                'name' => $partner->full_name,
                'is_deletable' => $is_deletable,
                'reason' => !$is_deletable ? "This record cannot be deleted. Please mark it as Suspended or Inactive instead." : null
            ];
        }

        return $results;
    }



    public function getPartnerProfileStats(int $id)
    {
        $partnerClass = addslashes(\App\Models\Partner\Partner::class);

        $stats = DB::table('partners as p')
            ->select(
                'p.id',
                DB::raw("(SELECT COUNT(DISTINCT id) FROM appointments WHERE (entity_type = '{$partnerClass}' AND entity_id = p.id) OR scheduled_by IN (SELECT id FROM users WHERE partner_id = p.id) OR assigned_for IN (SELECT id FROM users WHERE partner_id = p.id)) AS total_meetings"),
                DB::raw('(SELECT COUNT(*) FROM borrowers WHERE partner_id = p.id) AS total_clients'),
                DB::raw('(SELECT COUNT(*) FROM invoices WHERE partner_id = p.id) AS total_invoice'),
                DB::raw('(SELECT COUNT(*) FROM support_tickets WHERE partner_id = p.id) AS total_tickets'),
                DB::raw("(SELECT COALESCE(
                    (SELECT enrollment_type
                    FROM subscriptions
                    WHERE entity_id = p.id AND entity_type = '{$partnerClass}'
                    ORDER BY id DESC
                    LIMIT 1),
                    'N/A'
                    )) AS enrollment_type")
            )
            ->where('p.id', $id)
            ->first();

        if (!$stats) {
            return (object) [
                'id' => $id,
                'total_meetings' => 0,
                'total_clients' => 0,
                'total_invoice' => 0,
                'total_tickets' => 0,
                'enrollment_type' => 'N/A',
            ];
        }

        return $stats;
    }

    /**
     * Get the next partner ID (last partner ID plus 1).
     */
    public function getNextPartnerId(): int
    {
        return (Partner::max('id') ?? 0) + 1;
    }

    /**
     * Set the main partner user to suspended/inactive and revoke tokens.
     */
    private function restrictPartnerUsers(Partner $partner): void
    {
        $mainUser = $partner->partnerUser;

        if ($mainUser) {
            // Force Logout (Revoke all tokens)
            DB::table('oauth_access_tokens')
                ->where('user_id', $mainUser->id)
                ->update(['revoked' => true]);

        }
    }

    /**
     * Force logout for reactivated partner (optional but kept for internal logic consistency).
     */
    private function reactivatePartnerUsers(Partner $partner): void
    {
        Log::info("Main user for Partner ID: {$partner->id} is eligible for login again.");
    }

    /**
     * Get Tenant Config.
     */
    public function getTenantConfig(int $id)
    {
        return $this->findById($id);
    }

    /**
     * Update Tenant Config.
     */
    public function updateTenantConfig(int $id, array $data)
    {
        $partner = $this->findById($id);

        if (!$partner) {
            throw new \Exception(__('partner.error.not_found'));
        }

        $partner->update($data);

        return $partner;
    }

    /**
     * Generate a subdomain from company name.
     * Small letter, remove special letters, number, space.
     */
    private function generateSubdomain(string $companyName): string
    {
        // Small letter
        $subdomain = strtolower($companyName);
        // Remove special letters, number, space (keeping only a-z)
        $subdomain = preg_replace('/[^a-z]/', '', $subdomain);
        return $subdomain;
    }
}
