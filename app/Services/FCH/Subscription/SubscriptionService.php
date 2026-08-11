<?php

namespace App\Services\FCH\Subscription;

use Illuminate\Support\Str;
use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CronJobFailureMail;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\SubscriptionPlan;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;

class SubscriptionService
{
    use PaginatesApi;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
        protected SubscriptionCreationService $subscriptionCreationService
    ) {
    }


    /**
     * List subscriptions with pagination and filters.
     *
     * @param array $filters
     * @param int|null $partnerId
     * @param int|null $borrowerId
     * @return SubscriptionResource
     */
    public function list(array $filters, ?int $partnerId = null, ?int $borrowerId = null)
    {
        $query = $this->prepareSubscriptionQuery($filters, $partnerId, $borrowerId);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'));
    }

    /**
     * Fetch All Subscriptions (Global).
     *
     * @param array $filters
     * @return SubscriptionResource
     */
    public function fetchAll(array $filters)
    {
        $query = $this->prepareSubscriptionQuery($filters, null, null);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'));
    }

    /**
     * Prepare the subscription query with filters and sorting.
     */
    private function prepareSubscriptionQuery(array $filters, ?int $partnerId = null, ?int $borrowerId = null)
    {
        $query = Subscription::query()
            ->with([
                'partner:id,full_name,company_name,email',
                'borrower:id,first_name,last_name,email,partner_id',
                'borrower.partner:id,full_name,company_name,email',
                'enrollmentPhase',
                'platformFeePhase'
            ])
            ->select('subscriptions.*');

        $effectivePartnerId = $partnerId ?: ($filters['partner_id'] ?? null);
        if ($effectivePartnerId) {
            $query->where('entity_type', Partner::class)
                ->where('entity_id', $effectivePartnerId);
        }

        if ($borrowerId) {
            $query->where('entity_type', Borrower::class)
                ->where('entity_id', $borrowerId);
        }

        // Global Search
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        if (!empty($globalSearch)) {
            $query->search($globalSearch);
        }

        // Top-level status filter (mapping 1 -> Active, 0 -> Inactive)
        $status = $filters['status'] ?? null;

        if (is_array($status)) {
            $status = $status['value'] ?? null;
        }

        // Apply filter ONLY if real value exists
        if (filled($status)) {
            if (is_numeric($status)) {
                $status = ((int) $status === 1)
                    ? Subscription::STATUS_ACTIVE
                    : Subscription::STATUS_PENDING;
            }

            $query->where('subscriptions.status', $status);
        }

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']);

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $skipSorting = false;

        // Join Partners if searching or sorting by partner/company name
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $query->withPartner();

            if ($sortField === 'partner_name') {
                $query->orderBy('partners.full_name', $sortOrder);
                $skipSorting = true;
            }
        }

        // Join Borrowers if searching or sorting by borrower_name
        if ($sortField === 'borrower_name' || isset($rawFilters['borrower_name'])) {
            $query->withBorrower();

            if ($sortField === 'borrower_name') {
                // Concatenate first and last name for sorting
                $query->orderByRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) {$sortOrder}");
                $skipSorting = true;
            }
        }

        // Join Enrollment Phase for next_installment_date
        if ($sortField === 'next_installment_date' || isset($rawFilters['next_installment_date'])) {
            $query->withEnrollmentPhase();

            if ($sortField === 'next_installment_date') {
                $query->orderBy('ep.next_installment_date', $sortOrder);
                $skipSorting = true;
            }
        }

        // Join Platform Fee Phase for next_platform_fee_date
        if ($sortField === 'next_platform_fee_date' || isset($rawFilters['next_platform_fee_date'])) {
            $query->withPlatformFeePhase();

            if ($sortField === 'next_platform_fee_date') {
                $query->orderBy('pfp.next_platform_fee_date', $sortOrder);
                $skipSorting = true;
            }
        }

        // Created At Filter (Format: Y-m-d h:i A)
        $createdAtFilter = $rawFilters['created_at'] ?? null;
        $createdAt = null;

        if (is_array($createdAtFilter)) {
            $createdAt = $createdAtFilter['value'] ?? null;
        } elseif (is_string($createdAtFilter)) {
            $createdAt = $createdAtFilter;
        }

        if (!empty($createdAt) && is_string($createdAt)) {
            $query->whereRaw("DATE_FORMAT(subscriptions.created_at, '%Y-%m-%d %h:%i %p') LIKE ?", ["%{$createdAt}%"]);
            unset($rawFilters['created_at']);
        }

        return QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting,
                'aliases' => [
                    'partner_name' => 'partners.full_name',
                    'enrollment_type' => 'subscriptions.enrollment_type',
                    'payable_amount' => 'subscriptions.payable_amount',
                    'status' => 'subscriptions.status',
                    'created_at' => 'subscriptions.created_at',
                    'next_installment_date' => 'ep.next_installment_date',
                    'next_platform_fee_date' => 'pfp.next_platform_fee_date',
                ],
                'concat_fields' => [
                    'partner_name' => ['partners.full_name', 'partners.company_name'],
                    'borrower_name' => ['borrowers.first_name', 'borrowers.last_name']
                ]
            ]
        );
    }

    /**
     * Paginate and respond with resource.
     */
    private function paginateAndRespond($query, array $filters, string $message)
    {
        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            SubscriptionResource::class,
            $message
        );
    }

    /**
     * Create or Update Subscription
     *
     * @param array $searchFields
     * @param array $data
     * @return Subscription
     */
    public function createOrUpdate(array $searchFields, array $data)
    {
        // Handle installment_type logic
        if (isset($data['enrollment_type'])) {
            $data['installment_type'] = (Str::contains($data['enrollment_type'], [Subscription::ENROLLMENT_TYPE_PAYMENT_PLAN, Subscription::ENROLLMENT_TYPE_MONTHLY])) ? 1 : 0;
        }

        // Calculate subscription_amount_per_month
        if (!isset($data['subscription_amount_per_month'])) {
            $totalSubAmount = $data['total_subscription_amount'] ?? 0;
            $downPayment = $data['down_payment'] ?? 0;
            $installmentCount = $data['installment_count'] ?? 0;
            $installmentType = $data['installment_type'] ?? 0;

            if ($installmentType == 1 && $installmentCount > 0) {
                $data['subscription_amount_per_month'] = ($totalSubAmount - $downPayment) / $installmentCount;
            } else {
                // One-time / non-installment plan: use payable_amount, fall back to monthly_platform_fee.
                $data['subscription_amount_per_month'] = $data['payable_amount'] ?? ($data['monthly_platform_fee'] ?? 0);
            }
        }

        return Subscription::updateOrCreate($searchFields, $data);
    }

    /**
     * Get Subscription by ID
     *
     * @param int $id
     * @return Subscription|null
     */
    public function getById(int $id)
    {
        return Subscription::with([
            'partner',
            'borrower',
            'borrower.partner',
            'partnerPlan',
            'enrollmentPhase',
            'platformFeePhase'
        ])->find($id);
    }

    /**
     * Get Subscription by Partner ID
     *
     * @param int $partnerId
     * @return Subscription|null
     */
    public function getByPartnerId(int $partnerId)
    {
        return Subscription::where('entity_type', Partner::class)
            ->where('entity_id', $partnerId)
            ->orderBy('id', 'desc')
            ->with(['partner'])
            ->first();
    }

    /**
     * Get Subscription by Borrower ID
     */
    public function getByBorrowerId(int $borrowerId)
    {
        return Subscription::where('entity_type', Borrower::class)
            ->where('entity_id', $borrowerId)
            ->orderBy('id', 'desc')
            ->with(['borrower'])
            ->first();
    }

    /**
     * Start Subscription
     *
     * @param $entity
     * @param $plan
     * @return Subscription
     */
    public function startSubscription($entity, $plan, array $data = []): Subscription
    {
        return $this->subscriptionCreationService->startSubscription($entity, $plan, $data);
    }

    /**
     * Handle logic after a successful payment for an invoice.
     *
     * @param Invoice $invoice
     * @return void
     */
    public function handleSuccessfulPayment(Invoice $invoice)
    {
        $subscription = null;
        if ($invoice->subscription_id) {
            $subscription = Subscription::find($invoice->subscription_id);
        }

        if (!$subscription) {
            $subscription = Subscription::where('entity_type', $invoice->entity_type)
                ->where('entity_id', $invoice->entity_id)
                ->first();
        }

        if (!$subscription) {
            Log::warning('Subscription not found for payment', ['invoice_id' => $invoice->id]);
            return;
        }

        $phase = $subscription->current_phase;

        // ── USER SEAT ──────────────────────────────────────────────────────────
        if ($phase === Subscription::PHASE_USER_SEAT) {
            $monthlyFee = $subscription->monthly_platform_fee ?: 49.97;
            $monthsPaid = max(1, (int) round($invoice->payment_amount / $monthlyFee));

            $isFirstActivation = !$subscription->subscription_start_date;

            // Resolve user record
            $user = null;
            if ($subscription->entity_type === \App\Models\User\User::class) {
                $user = \App\Models\User\User::find($subscription->entity_id);
            } else {
                $user = \App\Models\User\User::where('entity_type', \App\Models\Employee\Employee::class)
                    ->where('entity_id', $subscription->entity_id)
                    ->first();
            }

            if ($isFirstActivation) {
                $plainPassword = bin2hex(random_bytes(4));
                $newEnd = now()->addMonths($monthsPaid);

                if ($user) {
                    $user->update([
                        'status'         => \App\Models\User\User::STATUS['active'],
                        'payment_status' => \App\Models\User\User::PAYMENT_STATUS_PAID,
                        'password'       => \Illuminate\Support\Facades\Hash::make($plainPassword),
                        'start_date'     => now(),
                        'end_date'       => $newEnd,
                    ]);

                    try {
                        Mail::to($user->email)->send(new \App\Mail\Payment\PaymentSuccessUserMail([
                            'amount'         => $invoice->payment_amount,
                            'currency'       => $invoice->currency,
                            'transaction_id' => 'SUB_' . $subscription->id,
                            'description'    => 'User seat activation',
                            'date'           => now(),
                            'user_name'      => $user->name,
                            'user_email'     => $user->email,
                            'plainPassword'  => $plainPassword,
                            'user'           => $user,
                        ], $user->partner_id));
                    } catch (\Exception $e) {
                        Log::error('Failed to send Seat Purchase Mail: ' . $e->getMessage());
                    }
                }

                $subscription->update([
                    'subscription_start_date' => now(),
                    'first_payment_date'      => now(),
                    'subscription_end_date'   => $newEnd,
                    'status'                  => Subscription::STATUS_ACTIVE,
                    'current_phase'           => Subscription::PHASE_USER_SEAT,
                ]);
            } else {
                // Renewal: extend subscription_end_date
                $currentEnd = Carbon::parse($subscription->subscription_end_date);
                $newEnd = $currentEnd->isPast() ? now()->addMonths($monthsPaid) : $currentEnd->addMonths($monthsPaid);

                $subscription->update([
                    'subscription_end_date' => $newEnd,
                    'status'                => Subscription::STATUS_ACTIVE,
                ]);

                if ($user) {
                    $user->update([
                        'payment_status' => \App\Models\User\User::PAYMENT_STATUS_PAID,
                        'end_date'       => $newEnd,
                        'status'         => \App\Models\User\User::STATUS['active'],
                    ]);
                }
            }
            return;
        }

        // ── PARTNER / BORROWER PHASES ──────────────────────────────────────────
        $plan = $subscription->partnerPlan;

        // Count paid non-platform-fee invoices for this subscription
        $invoiceCount = Invoice::where('subscription_id', $subscription->id)
            ->whereNotIn('type', [Invoice::INVOICE_TYPE_PLATFORM_FEE])
            ->where('status', Invoice::STATUS_PAID)
            ->count();

        $hasDownPayment  = $subscription->down_payment && $subscription->down_payment > 0;
        $installmentsPaid = $hasDownPayment ? max(0, $invoiceCount - 1) : $invoiceCount;

        $subscription->update(['installments_paid' => $installmentsPaid]);

        // Set start date on first payment
        if (!$subscription->subscription_start_date) {
            $subscription->update([
                'subscription_start_date' => now(),
                'first_payment_date'      => now(),
                'status'                  => Subscription::STATUS_ACTIVE,
            ]);
            $subscription->refresh();
        } else {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        $this->advancePhase($subscription, $plan);
    }

    /**
     * Advance the subscription phase after a successful payment.
     *
     * Sets current_phase and advances subscription_end_date as the single billing trigger.
     * next_installment_date and next_platform_fee_date are no longer written to.
     */
    private function advancePhase(Subscription $subscription, $plan): void
    {
        $phase = $subscription->current_phase;
        $nextEnd = now()->addMonth()->toDateString();

        if ($phase === Subscription::PHASE_ENROLLMENT) {
            // Down payment just paid — move to installment phase
            $subscription->update([
                'current_phase'        => Subscription::PHASE_INSTALLMENT,
                'subscription_end_date' => $nextEnd,
            ]);
            return;
        }

        if ($phase === Subscription::PHASE_INSTALLMENT) {
            $installmentCount = $subscription->installment_count ?? ($plan?->total_installment_count_upto ?? 0);

            if ($subscription->installments_paid < $installmentCount) {
                // More installments remain
                $subscription->update([
                    'current_phase'        => Subscription::PHASE_INSTALLMENT,
                    'subscription_end_date' => $nextEnd,
                ]);
            } else {
                // All installments paid — move to platform fee phase
                $subscription->update([
                    'current_phase'          => Subscription::PHASE_PLATFORM_FEE,
                    'subscription_end_date'  => $nextEnd,
                    'enrollment_completed_at' => now(),
                ]);
            }
            return;
        }

        if ($phase === Subscription::PHASE_PLATFORM_FEE) {
            $subscription->update([
                'current_phase'        => Subscription::PHASE_PLATFORM_FEE,
                'subscription_end_date' => $nextEnd,
            ]);
        }
    }
    /**
     * Check if an invoice already exists for the given cycle.
     *
     * @param Subscription $subscription
     * @param bool $isPlatformFee
     * @param string $cycleDate
     * @return bool
     */

    /**
     * Check if an invoice should be created for the subscription this cycle.
     *
     * Deduplication: one unpaid or paid invoice per subscription per calendar month.
     *
     * @param Subscription $subscription
     * @return bool
     */
    protected function createInvoiceIfDue(Subscription $subscription): bool
    {
        $today = Carbon::today();
        $dueDate = $subscription->subscription_end_date;

        if (!$dueDate) {
            return false;
        }

        $dueDate = Carbon::parse($dueDate);
        $generateBeforeDays   = config('subscription.generate_before_days', 5);
        $earliestGenerateDate = $dueDate->copy()->subDays($generateBeforeDays);

        if ($today < $earliestGenerateDate) {
            return false;
        }

        // Deduplication: one invoice per subscription per calendar month
        $currentMonth = $today->format('Y-m');
        $invoiceExistsThisMonth = Invoice::where('subscription_id', $subscription->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$currentMonth])
            ->exists();

        return !$invoiceExistsThisMonth;
    }

    /**
     * Create invoice for a subscription billing cycle (enrollment / platform fee).
     *
     * Phase data is read directly from the subscription model.
     * The subscription_phases table is kept in the database for historical records
     * but is no longer read from or written to by this method.
     *
     * @param Subscription $subscription
     * @param string       $type  'enrollment' | 'platform_fee'
     * @return void
     */
    protected function createInvoiceForSubscription(Subscription $subscription): void
    {
        try {
            DB::transaction(function () use ($subscription) {

                $entity = $subscription->entity;

                if (!$entity || $subscription->status !== Subscription::STATUS_ACTIVE) {
                    return;
                }

                // Status guard per entity type
                if ($subscription->entity_type === Borrower::class) {
                    if ($entity->status !== Borrower::STATUS_ACTIVE) return;
                    $entity->loadMissing('partner');
                    $partner = $entity->partner;
                    if (!$partner || $partner->status !== Partner::STATUS_ACTIVE) return;
                } elseif ($subscription->entity_type === Partner::class) {
                    if ($entity->status !== Partner::STATUS_ACTIVE) return;
                } elseif ($subscription->entity_type === \App\Models\User\User::class) {
                    if ($entity->status !== \App\Models\User\User::STATUS['active']) return;
                } elseif ($subscription->entity_type === Employee::class) {
                    $userRecord = \App\Models\User\User::where('entity_type', Employee::class)->where('entity_id', $subscription->entity_id)->first();
                    if (!$userRecord || $userRecord->status !== \App\Models\User\User::STATUS['active']) return;
                }

                if (!$this->createInvoiceIfDue($subscription)) {
                    return;
                }

                $phase = $subscription->current_phase;
                $isPlatformFee = $phase === Subscription::PHASE_PLATFORM_FEE;

                // Installment limit check
                if ($phase === Subscription::PHASE_INSTALLMENT) {
                    $invoiceCount  = Invoice::where('subscription_id', $subscription->id)
                        ->whereNotIn('type', [Invoice::INVOICE_TYPE_PLATFORM_FEE])
                        ->count();
                    $totalCount    = $subscription->installment_count ?? ($subscription->partnerPlan?->total_installment_count_upto ?? 0);
                    $hasDownPayment = $subscription->down_payment && $subscription->down_payment > 0;
                    $allowedInvoices = $hasDownPayment ? ($totalCount + 1) : $totalCount;

                    if ($totalCount && $invoiceCount >= $allowedInvoices) {
                        return;
                    }
                }

                // Determine payer and amount
                $isUserSeatPhase = $phase === Subscription::PHASE_USER_SEAT;
                $isPartnerPaying = $isUserSeatPhase;

                if ($subscription->entity_type === Borrower::class) {
                    $partnerId = $entity->partner_id;
                } elseif ($isUserSeatPhase) {
                    $partnerId = $entity->partner_id;
                } else {
                    $partnerId = $entity->id;
                }

                // Amount by phase
                if ($phase === Subscription::PHASE_PLATFORM_FEE) {
                    $amount = $subscription->monthly_platform_fee;
                } elseif ($phase === Subscription::PHASE_USER_SEAT) {
                    $amount = $subscription->monthly_platform_fee;
                } else {
                    // enrollment or installment
                    $invoiceCount = Invoice::where('subscription_id', $subscription->id)
                        ->whereNotIn('type', [Invoice::INVOICE_TYPE_PLATFORM_FEE])
                        ->count();
                    $hasDownPayment = $subscription->down_payment && $subscription->down_payment > 0;
                    if ($subscription->installment_type == 1 && $subscription->installment_count > 0) {
                        if ($invoiceCount == 0 && $hasDownPayment) {
                            $amount = $subscription->down_payment;
                        } else {
                            $amount = ($subscription->total_subscription_amount - $subscription->down_payment) / $subscription->installment_count;
                        }
                    } else {
                        $amount = $subscription->payable_amount;
                    }
                }

                // Entity display info
                $customerName = $entity->full_name ?? trim(($entity->first_name ?? '') . ' ' . ($entity->last_name ?? ''));
                $email        = $entity->email;

                // For user seat: bill the partner
                if ($isUserSeatPhase) {
                    $partner = Partner::find($entity->partner_id);
                    $customerName = $partner ? ($partner->company_name ?: $partner->full_name) : $customerName;
                    $email        = $partner ? $partner->email : $email;
                }

                $invoice = $this->invoiceService->create([
                    'partner_id'        => $partnerId,
                    'borrower_id'       => $subscription->entity_type === Borrower::class ? $subscription->entity_id : null,
                    'subscription_id'   => $subscription->id,
                    'entity_type'       => $isUserSeatPhase ? Partner::class : $subscription->entity_type,
                    'entity_id'         => $isUserSeatPhase ? $entity->partner_id : $subscription->entity_id,
                    'customer_name'     => $customerName,
                    'email'             => $email,
                    'country'           => $entity->country ?? null,
                    'payment_amount'    => $amount,
                    'currency'          => Invoice::CURRENCY_USD,
                    'status'            => Invoice::STATUS_UNPAID,
                    'type'              => $phase,  // mirrors Subscription::PHASE_* directly
                    'is_partner_paying' => $isPartnerPaying ? 1 : 0,
                    'is_platform_fee'   => $isPlatformFee ? 1 : 0,
                ]);

                Log::info('Invoice created successfully', ['invoice_id' => $invoice->id ?? null, 'phase' => $phase]);

                try {
                    $this->invoiceService->sendInvoice($invoice->id);
                } catch (\Exception $e) {
                    Log::error('Failed to send invoice email in batch process', [
                        'invoice_id' => $invoice->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to create invoice for subscription', [
                'subscription_id' => $subscription->id,
                'phase'           => $subscription->current_phase,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            $emails = explode(',', config('app.cron_notify_emails', ''));
            $emails = array_filter(array_map('trim', $emails));

            if (!empty($emails)) {
                $entity = $subscription->entity;
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name'      => 'generateDueInvoices — Failure',
                    'error_message' => $e->getMessage(),
                    'timestamp'     => now()->toDateTimeString(),
                    'stack_trace'   => $e->getTraceAsString(),
                    'user_email'    => $entity->email ?? 'N/A',
                    'user_id'       => $entity->id ?? 'N/A',
                    'partner_name'  => $entity->full_name ?? ($entity->partner?->full_name ?? 'N/A'),
                ]));
            }
        }
    }

    // Cron Job
    /**
     * Generate invoices for enrollment installment phase.
     *
     * Queries subscriptions table directly using current_phase + next_installment_date.
     */
    /**
     * Generate invoices for all active subscriptions whose subscription_end_date is due.
     *
     * This is the single unified invoice generator for all subscription types:
     *  - enrollment   (Partner/Borrower down payment pending)
     *  - installment  (Partner/Borrower installment phase)
     *  - platform_fee (Partner/Borrower platform fee phase)
     *  - user_seat    (User/Employee monthly seat renewal)
     *
     * current_phase determines the invoice type; subscription_end_date is the sole trigger.
     * next_installment_date and next_platform_fee_date are deprecated and no longer used.
     */
    public function generateDueInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        Subscription::with(['entity', 'partnerPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscription_end_date')
            ->whereDate('subscription_end_date', '<=', $targetDate)
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $this->createInvoiceForSubscription($subscription);
                }
            });
    }

    /**
     * @deprecated Use generateDueInvoices() instead.
     * Kept for backward compatibility with direct artisan calls.
     */
    public function generateInstallmentInvoices(): void
    {
        $this->generateDueInvoices();
    }

    /**
     * @deprecated Use generateDueInvoices() instead.
     * Kept for backward compatibility with direct artisan calls.
     */
    public function generatePlatformFeeInvoices(): void
    {
        // No-op: merged into generateDueInvoices()
    }

    /**
     * Get Subscription Statistics.
     */
    public function getStats(): array
    {
        $totalSubscriptions = Subscription::count();
        $totalActive = Subscription::where('status', Subscription::STATUS_ACTIVE)->count();

        // Expiring Soon (within 30 days)
        $expiringSoon = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscription_end_date')
            ->whereBetween('subscription_end_date', [now(), now()->addDays(30)])
            ->count();

        // Expired Subscriptions
        $expiredCount = Subscription::whereNotNull('subscription_end_date')
            ->where('subscription_end_date', '<', now())
            ->count();

        // Revenue this month (from paid invoices)
        $revenueThisMonth = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('payment_amount');

        return [
            'total_subscriptions' => $totalSubscriptions,
            'active_subscriptions' => $totalActive,
            'expiring_soon' => $expiringSoon,
            'expired_subscriptions' => $expiredCount,
            'revenue_this_month' => (float) $revenueThisMonth,
        ];
    }
}
