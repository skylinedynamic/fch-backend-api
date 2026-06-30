<?php

namespace App\Services\FCH\Common\Borrower;

use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Http\UploadedFile;
use App\Models\CreditBuilder\Tier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Borrower\BorrowerDocument;
use App\Models\ActivityLog\ActivityLog;
use App\Mail\Borrower\BorrowerPaymentLinkMail;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Services\FCH\Subscription\SubscriptionService;


class BorrowerService
{
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService,
        protected \App\Services\FCH\Common\ActivityLog\ActivityLogService $activityLogService,
    ) {
    }

    /**
     * Send a borrower email using the partner's active mail config.
     */
    private function sendBorrowerMail(string $email, object $mailable, int|null $partnerId): void
    {
        try {
            Mail::to($email)->send($mailable);
        } catch (\Exception $e) {
            Log::error('Borrower email failed', [
                'email' => $email,
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List borrowers with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        $query = Borrower::query()
            ->with(['user:id,entity_type,entity_id,is_free_trail', 'partner:id,full_name', 'assignedUser:id,name', 'tier:id,name', 'latestSubscription:id,enrollment_type'])
            ->select('borrowers.*');

        // Filter based on authenticated user's partner and host status
        if ($authUser && $authUser->partner) {
            $partnerId = $filters['partner_id']['value'] ?? ($filters['partner_id'] ?? null);
            if ($authUser->partner->is_host == 1) {
                // If user is a host, filter by partner_id if provided, otherwise show all
                if ($partnerId !== null && $partnerId !== '') {
                    $query->where('borrowers.partner_id', $partnerId);
                }
            } else {
                // If user is not a host, only show borrowers from their own partner
                $query->where('borrowers.partner_id', $authUser->partner_id);
            }
        }

        // Filter by current_tier (supports ID or Name)
        $currentTier = $filters['current_tier']['value'] ?? ($filters['current_tier'] ?? null);
        if ($currentTier !== null && $currentTier !== '') {
            if (is_numeric($currentTier)) {
                $query->where('borrowers.current_tier', $currentTier);
            } else {
                $query->whereHas('tier', function ($q) use ($currentTier) {
                    $q->where('name', 'like', "%{$currentTier}%");
                });
            }
        }

        // Filter by assign_id
        $assignId = $filters['assigned_user_id']['value'] ?? ($filters['assigned_user_id'] ?? ($filters['assign_id']['value'] ?? ($filters['assign_id'] ?? null)));
        if ($assignId !== null && $assignId !== '') {
            $query->where('borrowers.assign_id', $assignId);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Manual column filters handling
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        // 1. Filter by Partner Name
        if (isset($rawFilters['partner_name'])) {
            $constraints = $rawFilters['partner_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('partner', function ($q) use ($value) {
                        $q->where('full_name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['partner_name']);
        }

        // 2. Filter by Assigned User Name
        if (isset($rawFilters['assign_name'])) {
            $constraints = $rawFilters['assign_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('assignedUser', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['assign_name']);
        }

        // 3. Filter by Current Tier Name
        if (isset($rawFilters['current_tier_name'])) {
            $constraints = $rawFilters['current_tier_name']['constraints'] ?? [];
            foreach ($constraints as $constraint) {
                $value = $constraint['value'] ?? null;
                if ($value) {
                    $query->whereHas('tier', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                }
            }
            unset($rawFilters['current_tier_name']);
        }

        // Handle Relation Sorting
        $skipSorting = false;
        if ($sortField === 'assign_name') {
            $query->orderBy(
                User::select('name')
                    ->whereColumn('users.id', 'borrowers.assign_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->orderBy(
                Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrowers.partner_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === 'current_tier_name') {
            $query->orderBy(
                Tier::select('name')
                    ->whereColumn('tiers.id', 'borrowers.current_tier')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting,
                'concat_fields' => [
                    'full_name' => ['borrowers.first_name', 'borrowers.last_name'],
                ],
                'aliases' => [
                    'email' => 'borrowers.email',
                    'phone_number' => 'borrowers.phone_number',
                    'first_name' => 'borrowers.first_name',
                    'last_name' => 'borrowers.last_name',
                    'status' => 'borrowers.status',
                    'created_at' => 'date_range',
                ],
            ]
        );

        // Calculate statistics before pagination
        $statistics = [
            'total_borrowers' => (clone $query)->count(),
            'active_borrowers' => (clone $query)->where('status', Borrower::STATUS_ACTIVE)->count(),
            'inactive_borrowers' => (clone $query)->whereIn('status', [Borrower::STATUS_INACTIVE, Borrower::STATUS_SUSPENDED])->count(),
            'new_this_month' => (clone $query)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerResource::class,
            __('borrower.success.retrieved'),
            200,
            ['statistics' => $statistics]
        );
    }

    public function getBorrowerProfileStats(int $id)
    {
        $borrower = Borrower::findOrFail($id);
        $borrowerClass = str_replace('\\', '\\\\', Borrower::class);

        $stats = DB::table('borrowers as b')
            ->select([
                'b.id',
                DB::raw("(SELECT COUNT(*) FROM appointments WHERE entity_type = '{$borrowerClass}' AND entity_id = b.id) as total_meetings"),
                DB::raw("(SELECT COUNT(*) FROM invoices WHERE borrower_id = b.id OR (entity_type = '{$borrowerClass}' AND entity_id = b.id)) as total_invoices"),
                DB::raw("(SELECT COALESCE((SELECT enrollment_type FROM subscriptions WHERE entity_type = '{$borrowerClass}' AND entity_id = b.id ORDER BY id DESC LIMIT 1), 'N/A')) as enrollment_type"),
                DB::raw("(SELECT COUNT(*) FROM pre_qualification_requests WHERE borrower_id = b.id) as total_prequalification"),
                DB::raw("0 as total_tickets"),
                DB::raw("(SELECT COUNT(*) FROM borrower_documents WHERE borrower_id = b.id) as total_documents")
            ])
            ->where('b.id', $id)
            ->first();

        return $stats;
    }

    /**
     * Resolve a borrower subscription plan by plan ID; fall back to the one-time default plan.
     * Returns [plan, planId].
     */
    private function resolveBorrowerPlan(?int $planId): array
    {
        $plan = null;
        if ($planId) {
            $plan = SubscriptionPlan::where('id', $planId)->where('entity_type', Borrower::class)->first();
        }
        if (!$plan) {
            $plan = SubscriptionPlan::where('enrollment_type_slug', SubscriptionPlan::BORROWER_ENROLMENT_TYPE_ONE_TIME)
                ->where('entity_type', Borrower::class)
                ->first();
            $planId = $plan ? $plan->id : null;
        }
        return [$plan, $planId];
    }

    /**
     * Calculate borrower subscription amounts, applying a custom enrollment payment where supplied.
     * Returns ['amount', 'totalInstallments', 'total', 'isCustom'].
     */
    private function calculateBorrowerSubscriptionAmounts(?SubscriptionPlan $plan, array $data, float $fallbackAmount = 2497.00): array
    {
        $default = $plan ? (float) ($plan->payment_amount ?? $fallbackAmount) : $fallbackAmount;
        $amount = (isset($data['enrollment_payment']) && $data['enrollment_payment'] !== '')
            ? (float) $data['enrollment_payment']
            : $default;

        $totalInstallments = $plan ? (int) ($plan->total_installments_count ?? 1) : 1;
        $total = $plan ? (float) ($plan->total_amount ?? $amount) : $amount;

        if (isset($data['enrollment_payment']) && $data['enrollment_payment'] !== '') {
            $total = $amount * $totalInstallments;
        }

        $isCustom = false;
        if ($plan) {
            $isCustom = (abs($amount - (float) ($plan->payment_amount ?? 0)) > 0.01)
                || (abs($total - (float) ($plan->total_amount ?? 0)) > 0.01);
        }

        return compact('amount', 'totalInstallments', 'total', 'isCustom');
    }

    /**
     * Create partner + borrower invoices, start the subscription, and dispatch the appropriate email.
     *
     * This encapsulates the PAID / STANDARD billing path that is shared between store() and update().
     *
     * @param  Borrower  $borrower
     * @param  Partner   $partner
     * @param  object    $user
     * @param  array     $data
     * @param  array     $mailData  Base mail data (user, business_info, optionally plain_password)
     * @param  bool      $isHostCreating
     * @param  bool      $isMigration  When true, sends BorrowerMigrationMail instead of payment-link/account-created mail
     */
    private function buildBorrowerInvoicesAndSendEmail(
        Borrower $borrower,
        Partner $partner,
        object $user,
        array $data,
        array $mailData,
        bool $isHostCreating,
        bool $isMigration = false
    ): void {
        $planId = isset($data['plan_id']) ? (int) $data['plan_id'] : null;
        $partnerTypeChecking = in_array($partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED]);

        if ($partnerTypeChecking && !$isHostCreating) {
            // WL / Certified partner path: partner pays a fixed fee, borrower pays enrollment
            $borrower->update(['partner_payment_for_me' => false]);

            $wlPartnerFeePlan = SubscriptionPlan::where('enrollment_type_slug', SubscriptionPlan::BORROWER_FIXED_ENROLLMENT)
                ->where('entity_type', Borrower::class)
                ->first();
            $wlPartnerFeeAmount = $wlPartnerFeePlan ? (float) ($wlPartnerFeePlan->payment_amount ?? 800.00) : 800.00;

            $partnerInvoice = $this->invoiceService->create([
                'partner_id' => $borrower->partner_id,
                'borrower_id' => $borrower->id,
                'entity_type' => Partner::class,
                'entity_id' => $borrower->partner_id,
                'customer_name' => $partner->full_name,
                'email' => $partner->email,
                'country' => $partner->country,
                'payment_amount' => $wlPartnerFeeAmount,
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => true,
                'is_platform_fee' => false,
            ]);
            $this->activityLogService->logModelEvent($partnerInvoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);

            [$plan] = $this->resolveBorrowerPlan($planId);
            $amounts = $this->calculateBorrowerSubscriptionAmounts($plan, $data);

            $invoice = $this->invoiceService->create([
                'partner_id' => $borrower->partner_id,
                'borrower_id' => $borrower->id,
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
                'customer_name' => $borrower->full_name,
                'email' => $borrower->email,
                'country' => $borrower->country,
                'payment_amount' => $amounts['amount'],
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => false,
                'is_platform_fee' => false,
            ]);
            $this->activityLogService->logModelEvent($invoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);
        } else {
            // Host / Direct path
            $plan = SubscriptionPlan::where('id', $planId)->where('entity_type', Borrower::class)->first();
            $amounts = $this->calculateBorrowerSubscriptionAmounts($plan, $data, 0);

            $invoice = $this->invoiceService->create([
                'partner_id' => $borrower->partner_id,
                'borrower_id' => $borrower->id,
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
                'customer_name' => $borrower->full_name,
                'email' => $borrower->email,
                'country' => $borrower->country,
                'payment_amount' => $amounts['amount'],
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => $isHostCreating ? false : true,
                'is_platform_fee' => false,
            ]);
            $this->activityLogService->logModelEvent($invoice, ActivityLog::LOG_TYPE_INVOICE_CREATE);
        }

        $this->subscriptionService->startSubscription($borrower, $plan, [
            'monthly_platform_fee' => 0,
            'total_subscription_amount' => $amounts['total'],
            'payable_amount' => $amounts['amount'],
            'is_custom' => $amounts['isCustom'],
        ]);

        if ($isMigration) {
            // Free-trial → paid migration: only notify if there's an amount due
            if ($amounts['amount'] > 0) {
                $mailData['payment_link'] = concatUrl(getBorrowerDomain($invoice->partner_id), 'invoices', config('app.frontend_borrower_url'));
                $mailData['amount'] = $invoice->payment_amount;
                $this->sendBorrowerMail($user->email, new \App\Mail\Borrower\BorrowerMigrationMail($mailData, $borrower->partner_id), $borrower->partner_id);
            }
        } elseif ($isHostCreating) {
            if ($amounts['amount'] > 0) {
                $mailData['payment_link'] = concatUrl(getBorrowerDomain($invoice->partner_id), 'invoices', config('app.frontend_borrower_url'));
                $mailData['amount'] = $invoice->payment_amount;
                $this->sendBorrowerMail($user->email, new BorrowerPaymentLinkMail($mailData, $borrower->partner_id), $borrower->partner_id);
            } else {
                $this->sendBorrowerMail($user->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
            }
        } else {
            $this->sendBorrowerMail($user->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
        }
    }

    /**
     * Store a new borrower.
     */
    public function store(array $data)
    {
        DB::beginTransaction();
        try {

            $authUser = auth()->user();
            if (empty($data['partner_id'])) {
                $data['partner_id'] = $authUser->partner_id;
            } elseif ($authUser->partner && $authUser->partner->is_host != 1 && $data['partner_id'] != $authUser->partner_id) {
                // Non-hosts can only create borrowers for their own partner
                $data['partner_id'] = $authUser->partner_id;
            }

            $data['current_tier'] = Tier::orderBy('order', 'asc')->first()?->id;
            $data['status'] = Borrower::STATUS_ONBOARDING;

            // Filter out fields that are not columns in the borrowers table or are used for mapping
            $exceptFields = [
                'plan_id',
                'enrollment_payment',
                'payment_amount',
                'is_partner_paying',
                'amount',
                'business_other_city',
                'is_free_trail',
                'free_trail_end_date'
            ];
            $borrowerData = collect($data)->except($exceptFields)->toArray();
            $borrowerData['business_start_date'] = isset($data['business_start_date']) ? normalizeDate($data['business_start_date']) : null;
            $borrowerData['date_of_birth'] = isset($data['date_of_birth']) ? normalizeDate($data['date_of_birth']) : null;

            // Handle city overrides if 'other' city is provided
            if (isset($data['other_city'])) {
                $borrowerData['city'] = $data['other_city'];
            }
            if (isset($data['business_other_city'])) {
                $borrowerData['business_city'] = $data['business_other_city'];
            }

            $borrower = Borrower::create($borrowerData);

            // Log Borrower Creation
            $this->activityLogService->logModelEvent($borrower, ActivityLog::LOG_TYPE_BORROWER_CREATE);

            // Create associated User if needed
            $userDataResult = null;
            $userDataResult = $this->createOrUpdateUser($data, $borrower);
            Log::info('User data', ['userDataResult' => $userDataResult]);

            $user = $userDataResult['user'];
            $plainPassword = $userDataResult['plain_password'];

            if ($user && $plainPassword) {
                $clientPartner = Partner::where('id', $data['partner_id'])->first();
                $authUser = Auth::user();
                $isHostCreating = $authUser && $authUser->partner && $authUser->partner->is_host;

                $mailData = [
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'business_info' => [
                        'company_name' => $clientPartner->company_name ?? 'Four Corner Funding',
                        'company_logo' => $clientPartner->company_logo ?? 'logo.png',
                        'email' => $clientPartner->email ?? config('mail.from.address'),
                        'phone_number' => $clientPartner->phone_number ?? '',
                        'address_line_1' => $clientPartner->address_line_1 ?? '',
                    ]
                ];

                $partner = Partner::find($borrower->partner_id);

                // ── FREE TRIAL PATH ───────────────────────────────────────────────────────
                if (!empty($data['is_free_trail']) && $data['is_free_trail']) {
                    $trialEndDate = $data['free_trail_end_date'] ?? null;
                    $this->activityLogService->logModelEvent(
                        $borrower,
                        ActivityLog::LOG_TYPE_BORROWER_FREE_TRIAL,
                        [],
                        null,
                        __('borrower.free_trial_created_log', ['date' => $trialEndDate ?? 'N/A'])
                    );

                    $this->sendBorrowerMail(
                        $user->email,
                        new \App\Mail\Trial\BorrowerTrialMail(array_merge($mailData, ['trial_end_date' => $trialEndDate]), $borrower->partner_id),
                        $borrower->partner_id
                    );

                    $partnerTypeChecking = $partner && in_array($partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED]);
                    if ($partnerTypeChecking && !$isHostCreating) {
                        $borrower->update(['partner_payment_for_me' => false]);
                    }
                }
                // ── PAID / STANDARD PATH ─────────────────────────────────────────────────
                else {
                    $this->buildBorrowerInvoicesAndSendEmail(
                        $borrower,
                        $partner,
                        $user,
                        $data,
                        $mailData,
                        $isHostCreating
                    );
                }
            }
            DB::commit();

            return [
                'borrower' => $borrower->fresh(),
                'user' => $userDataResult['user'] ?? null,
                'plain_password' => $userDataResult['plain_password'] ?? null,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Borrower creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update an existing borrower.
     */
    public function update(Borrower $borrower, array $data)
    {
        DB::beginTransaction();
        try {
            $user = $borrower->user;
            $wasFreeTrial = $user ? (bool) $user->is_free_trail : false;

            if (isset($data['business_logo']) && $data['business_logo'] instanceof UploadedFile) {
                if ($borrower->business_logo) {
                    $this->deleteFile($borrower->business_logo, 'public');
                }
                $upload = $this->uploadFile($data['business_logo'], "borrowers/{$borrower->id}", 'public');
                $data['business_logo'] = $upload['path'];
            }
            $exceptFields = [
                'enrollment_payment',
                'plan_id',
                'is_partner_paying',
                'business_other_city',
                'is_free_trail',
                'free_trail_end_date'
            ];
            $borrowerData = collect($data)->except($exceptFields)->toArray();
            $borrowerData['business_start_date'] = isset($data['business_start_date']) ? normalizeDate($data['business_start_date']) : $borrower->business_start_date;
            $borrowerData['date_of_birth'] = isset($data['date_of_birth']) ? normalizeDate($data['date_of_birth']) : $borrower->date_of_birth;

            // Handle city overrides if 'other' city is provided
            if (isset($data['other_city'])) {
                $borrowerData['city'] = $data['other_city'];
            }
            if (isset($data['business_other_city'])) {
                $borrowerData['business_city'] = $data['business_other_city'];
            }
            $oldStatus = $borrower->status;
            $borrower->update($borrowerData);

            // Sync user status if borrower status changed
            if ($borrower->status !== $oldStatus) {
                if (in_array($borrower->status, [Borrower::STATUS_SUSPENDED, Borrower::STATUS_INACTIVE])) {
                    $this->restrictBorrowerUser($borrower);
                } elseif ($borrower->status === Borrower::STATUS_ACTIVE) {
                    $this->reactivateBorrowerUser($borrower);
                }
            }

            $changes = $borrower->getChanges();
            unset($changes['updated_at']);

            if (!empty($changes)) {
                $extraProps = [];
                if (isset($changes['email'])) {
                    $extraProps['new_email'] = $changes['email'];
                }

                $isEmailOnly = (count($changes) === 1 && isset($changes['email']));
                $contactFields = ['email', 'phone_number', 'business_phone'];

                $changedKeys = array_keys($changes);
                $isContactOnly = count(array_diff($changedKeys, $contactFields)) === 0;

                $logType = ActivityLog::LOG_TYPE_BORROWER_UPDATE;
                if ($isEmailOnly) {
                    $logType = ActivityLog::LOG_TYPE_EMAIL_UPDATE;
                } elseif ($isContactOnly) {
                    $logType = ActivityLog::LOG_TYPE_BORROWER_CONTACT_UPDATE;
                }

                $changedFields = [];
                foreach ($changes as $field => $newValue) {
                    $name = ucwords(str_replace('_', ' ', $field));
                    $val = (string) $newValue;
                    $val = $val === '' ? 'empty' : $val;
                    $changedFields[] = "{$name} was updated to {$val}";
                }
                $fieldsList = implode(', ', $changedFields);

                $customDescription = $isEmailOnly ? null : "{$fieldsList}.";

                $this->activityLogService->logModelEvent(
                    $borrower,
                    $logType,
                    $extraProps,
                    null,
                    $customDescription
                );
            }

            // Update subscription if custom enrollment payment is provided
            $subscription = $borrower->latestSubscription;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($subscription) {
                $plan = $subscription->subscriptionPlan;
                $updateSubData = [];

                // Determine final values to reassess is_custom status
                $finalEnrollment = (isset($data['enrollment_payment']) && $data['enrollment_payment'] !== '')
                    ? (float) $data['enrollment_payment']
                    : (float) $subscription->payable_amount;

                $totalInstallments = (int) ($plan->total_installments_count ?? 1);
                $finalTotal = $finalEnrollment * $totalInstallments;

                // Mark as custom if enrollment payment or total differs from the original plan
                $isCustom = false;
                if ($plan) {
                    $isCustom = (abs($finalEnrollment - (float) ($plan->payment_amount ?? 0)) > 0.01) ||
                        (abs($finalTotal - (float) ($plan->total_amount ?? 0)) > 0.01);
                }

                $updateSubData = [
                    'payable_amount' => $finalEnrollment,
                    'total_subscription_amount' => $finalTotal,
                    'is_custom' => $isCustom,
                ];

                $subscription->update($updateSubData);
                Log::info('Borrower subscription reassessed and updated', [
                    'subscription_id' => $subscription->id,
                    'is_custom' => $isCustom,
                    'payable' => $finalEnrollment,
                    'total' => $finalTotal
                ]);
            } elseif ($wasFreeTrial && !$isFreeTrialInput) {
                // Transition off free trial
                $clientPartner = Partner::where('id', $borrower->partner_id)->first();
                $authUser = Auth::user();
                $isHostCreating = $authUser && $authUser->partner && $authUser->partner->is_host;

                $mailData = [
                    'user' => $user,
                    'business_info' => [
                        'company_name' => $clientPartner->company_name ?? 'Four Corner Funding',
                        'company_logo' => $clientPartner->company_logo ?? 'logo.png',
                        'email' => $clientPartner->email ?? config('mail.from.address'),
                        'phone_number' => $clientPartner->phone_number ?? '',
                        'address_line_1' => $clientPartner->address_line_1 ?? '',
                    ],
                ];

                $partner = Partner::find($borrower->partner_id);
                $this->buildBorrowerInvoicesAndSendEmail(
                    $borrower,
                    $partner,
                    $user,
                    $data,
                    $mailData,
                    $isHostCreating,
                    isMigration: true
                );
            }

            // 1. Update associated User if exists
            $user = $borrower->user;
            if ($user) {
                $userResult = $this->createOrUpdateUser($data, $borrower, $user);
                $updatedUser = $userResult['user'];
                $plainPassword = $userResult['plain_password'];

                if ($plainPassword) {
                    $hostPartner = Partner::where('is_host', 1)->first();
                    $mailData = [
                        'user' => $updatedUser,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => $hostPartner->company_name ?? 'Four Corner Funding',
                            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                            'email' => $hostPartner->email ?? config('mail.from.address'),
                            'phone_number' => $hostPartner->phone_number ?? '',
                            'address_line_1' => $hostPartner->address_line_1 ?? '',
                        ],
                        'is_update' => true,
                    ];
                    Log::info('📧 Sending account update email to borrower due to email change', ['email' => $updatedUser->email]);
                    $this->sendBorrowerMail($updatedUser->email, new BorrowerAccountCreatedMail($mailData, $borrower->partner_id), $borrower->partner_id);
                }
            } elseif (!empty($data['email'])) {
                // Create user if email is provided and user doesn't exist
                $this->createOrUpdateUser($data, $borrower);
            }


            $user->update(['is_refresh' => true]);
            DB::commit();

            // Return consistent format or just borrower? Controller expects resource.
            // Keeping it simple as controller will be updated to handle array for store, but update often doesn't need email.
            return $borrower->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Borrower update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update borrower business financials.
     */
    public function updateBusinessFinancials(Borrower $borrower, array $data)
    {
        $borrower->update($data);
        return $borrower->fresh();
    }

    /**
     * Get borrower details.
     */
    public function getDetails(int $id)
    {
        return Borrower::when(auth()->user()->user_type == User::PARTNER, function ($query) {
            $query->where('partner_id', auth()->user()->partner_id);
        })
            ->with('partner', 'latestSubscription')
            ->find($id);
    }

    /**
     * Delete borrowers.
     */
    public function delete(array $ids)
    {

        try {
            DB::beginTransaction();
            $borrowers = Borrower::whereIn('id', $ids)->get();
            foreach ($borrowers as $borrower) {
                $borrower->safeDeleteWithRelations();
            }
            DB::commit();

            return ApiResponse::success([], __('borrower.success.deleted'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Borrower deletion failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Check if multiple borrowers are deletable
     */
    public function checkDeletable(array $ids): array
    {
        $borrowers = Borrower::whereIn('id', $ids)->get();
        $results = [];

        foreach ($borrowers as $borrower) {
            $is_deletable = $borrower->isDeletable();
            $results[] = [
                'id' => $borrower->id,
                'name' => $borrower->full_name,
                'is_deletable' => $is_deletable,
                'reason' => !$is_deletable ? "This record cannot be deleted. Please mark it as Suspended or Inactive instead." : null
            ];
        }

        return $results;
    }

    /**
     * Create or update user for borrower.
     */
    private function createOrUpdateUser(array $data, Borrower $borrower, ?User $existingUser = null)
    {
        $userData = [];

        if (isset($data['first_name']) && isset($data['last_name'])) {
            $userData['name'] = $data['first_name'] . ' ' . $data['last_name'];
        }

        if (array_key_exists('is_free_trail', $data)) {
            $userData['is_free_trail'] = $data['is_free_trail'] ? 1 : 0;
        }

        if (array_key_exists('free_trail_end_date', $data)) {
            $userData['free_trail_end_date'] = $data['free_trail_end_date'] ? normalizeDate($data['free_trail_end_date']) : null;
        }

        if (isset($data['email'])) {
            $userData['email'] = $data['email'];
        }

        if (isset($data['phone_number'])) {
            $userData['phone_number'] = $data['phone_number'];
        }

        if (!$existingUser) {
            // Defaults for new user
            $userData['user_type'] = User::BORROWER;
            $userData['entity_type'] = Borrower::class;
            $userData['entity_id'] = $borrower->id;
            $userData['partner_id'] = $borrower->partner_id;
        }

        if ($existingUser) {
            $plainPassword = null;
            // Check if email is changing and present in update data
            if (isset($userData['email']) && $existingUser->email !== $userData['email']) {
                $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
                $userData['password'] = bcrypt($plainPassword);
                Log::info('Password reset for Borrower user due to email change', ['user_id' => $existingUser->id]);
            }

            $existingUser->update($userData);
            return ['user' => $existingUser, 'plain_password' => $plainPassword];
        }

        // Check if user exists by email to avoid duplicates if not linked
        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $user->update($userData);
            return ['user' => $user, 'plain_password' => null];
        }

        $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
        $userData['password'] = bcrypt($plainPassword);

        $newUser = User::create($userData);

        // Assign 'Borrower' role based on partner_id
        $role = Role::where('name', User::BORROWER)
            ->where('partner_id', $borrower->partner_id)
            ->first();

        if ($role) {
            $newUser->assignRole($role);
        }

        return ['user' => $newUser, 'plain_password' => $plainPassword];
    }

    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id, name, email, phone_number.
     * 
     * If auth user is_host == 1: returns all users
     * Otherwise: returns only users filtered by partner_id
     */
    public function getAllUsers()
    {
        // Get authenticated user
        $authUser = auth()->user();

        // Base query: users where entity_type is Employee or NULL
        $query = User::query()
            ->where(function ($q) {
                $q->where('entity_type', 'App\\Models\\Borrower\\Borrower')
                    ->orWhereNull('entity_type');
            });

        // Apply partner_id filter if user is NOT a host
        if ($authUser && $authUser->partner) {
            // Check if the authenticated user's partner is a host
            if ($authUser->partner->is_host != 1) {
                // Not a host: filter by partner_id
                $query->where('partner_id', $authUser->partner_id);
            }
            // If is_host == 1: no filter applied, returns all users
        }

        $users = $query
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }


    /**
     * Get Borrower Documents.
     */
    public function getBorrowerDocuments(int $borrowerId)
    {

        $documents = BorrowerDocument::with('documentType')
            ->where('borrower_id', $borrowerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                $size = '0 KB';
                $relativePath = $doc->file_path;
                if ($relativePath && str_contains($relativePath, '/storage/')) {
                    $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
                }

                // Use relative path for storage operations
                if ($relativePath && Storage::disk('public')->exists($relativePath)) {
                    $sizeBytes = Storage::disk('public')->size($relativePath);
                    $size = $this->formatSizeUnits($sizeBytes);
                }

                return [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_name' => $doc->file_name ?: ($doc->getRawOriginal('file_path') ? basename($doc->getRawOriginal('file_path')) : 'N/A'),
                    'type' => $doc->documentType->name ?? 'Other',
                    'rejection_reason' => $doc->rejection_reason ?? '',
                    'size' => $doc->file_size ?: '0 KB',
                    'status' => $doc->status ?: BorrowerDocument::STATUS_UPLOADED,
                    'date' => $doc->created_at ? $doc->created_at->format('Y-m-d') : 'N/A',
                    'document_url' => $doc->file_path,
                    'url' => $doc->file_path,
                    'download_url' => url("api/v1/common/borrowers-documents/download/{$doc->id}"),
                ];
            });


        return $documents;
    }

    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }
    /**
     * Force logout for suspended/inactive borrowers.
     */
    private function restrictBorrowerUser(Borrower $borrower): void
    {
        $user = $borrower->user;
        if ($user) {
            // Force Logout (Revoke all tokens)
            DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->update(['revoked' => true]);

            Log::info("Force logged out borrower user for Borrower ID: {$borrower->id} (User ID: {$user->id}) due to borrower status being set to {$borrower->status}");
        }
    }

    /**
     * Log reactivation of borrower.
     */
    private function reactivateBorrowerUser(Borrower $borrower): void
    {
        Log::info("Borrower user for Borrower ID: {$borrower->id} is eligible for login again.");
    }
}
