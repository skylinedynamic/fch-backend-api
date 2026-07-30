<?php

namespace App\Services\FCH\Subscription;

use Illuminate\Support\Str;
use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CronJobFailureMail;
use App\Services\FCH\InvoiceService;
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
        protected ActivityLogService $activityLogService
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

        return Subscription::updateOrCreate($searchFields, $data);
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
        $data['subscription_plan_id'] = $data['subscription_plan_id'] ?? $plan?->id;
        $data['partner_type'] = $data['partner_type'] ?? $plan?->partner_type;
        $data['enrollment_type'] = $data['enrollment_type'] ?? $plan?->enrollment_type;
        $data['installment_type'] = $data['installment_type'] ?? (($plan && $plan->isInstallmentPlan()) ? 1 : 0);
        $data['installment_count'] = $data['installment_count'] ?? $plan?->total_installments_count ?? 0;
        $data['monthly_platform_fee'] = $data['monthly_platform_fee'] ?? $plan?->platform_fee ?? 0;
        $data['payable_amount'] = $data['payable_amount'] ?? $plan?->payment_amount ?? 0;
        $data['down_payment'] = $data['down_payment'] ?? 0;
        $data['total_subscription_amount'] = $data['total_subscription_amount'] ?? $plan?->total_amount ?? 0;
        $data['status'] = $data['status'] ?? Subscription::STATUS_PENDING;

        $subscriptionFillable = (new Subscription())->getFillable();
        $subscriptionData = array_intersect_key($data, array_flip($subscriptionFillable));

        $subscription = Subscription::updateOrCreate(
            ['entity_type' => get_class($entity), 'entity_id' => $entity->id],
            $subscriptionData
        );

        return $subscription->fresh();
    }

    /**
     * Handle logic after a successful payment for an invoice.
     *
     * @param Invoice $invoice
     * @return void
     */
    public function handleSuccessfulPayment(Invoice $invoice)
    {
        $subscription = Subscription::where('entity_type', $invoice->entity_type)
            ->where('entity_id', $invoice->entity_id)
            ->first();

        if (!$subscription) {
            Log::warning('Subscription not found for payment', ['invoice_id' => $invoice->id]);
            return;
        }

        $plan = $subscription->partnerPlan;
        $periodMonths = $plan->subscription_period_month ?? ($plan->total_installments_count ?? 1);

        // Count paid enrollment invoices (this includes down payment + installments)
        $invoiceCount = Invoice::where('entity_type', $invoice->entity_type)
            ->where('entity_id', $invoice->entity_id)
            ->where('is_platform_fee', false)
            ->where('status', Invoice::STATUS_PAID)
            ->count();

        // If there is a down payment, the first paid invoice is the down payment (not an installment)
        $hasDownPayment = $subscription->down_payment && $subscription->down_payment > 0;
        $installmentsPaid = $hasDownPayment ? max(0, $invoiceCount - 1) : $invoiceCount;

        $subscription->update(['installments_paid' => $installmentsPaid]);

        // Set subscription start / end / platform fee start dates on first payment
        if (!$subscription->subscription_start_date) {
            $subscription->update([
                'subscription_start_date' => now(),
                'first_payment_date'      => now(),
                'subscription_end_date'   => $plan->subscription_period_month ? now()->addMonths($periodMonths) : null,
                'platform_fee_start_date' => $plan->subscription_period_month ? now()->addMonths($periodMonths)->addDay() : null,
                'status'                  => Subscription::STATUS_ACTIVE,
            ]);
        } else {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        $this->completeEnrollment($subscription, $plan);
    }

    /**
     * Complete enrollment and schedule the next billing date on the subscription.
     *
     * All phase state is stored directly on the subscriptions table.
     * The subscription_phases table is kept in the database but is no longer
     * written to by this service.
     *
     * @param Subscription $subscription
     * @param mixed        $plan
     * @return void
     */
    private function completeEnrollment(Subscription $subscription, $plan): void
    {
        $next_installment_date   = null;
        $next_platform_fee_date  = null;
        $enrollment_completed_at = null;
        $currentPhase            = Subscription::PHASE_PLATFORM_FEE;

        if ($plan->entity_type == Borrower::class) {
            if ($subscription->installment_type == 1 && $subscription->installments_paid < $plan->total_installments_count) {
                $next_installment_date  = $subscription->next_installment_date
                    ? $subscription->next_installment_date->addMonth()
                    : $subscription->subscription_start_date->addMonth();
                $currentPhase           = Subscription::PHASE_ENROLLMENT;
            } else {
                $currentPhase           = Subscription::PHASE_ENROLLMENT;
                $enrollment_completed_at = now();
            }
        } else {
            if ($subscription->installment_type == 0 && $subscription->entity_type == Partner::class) {
                $currentPhase          = Subscription::PHASE_PLATFORM_FEE;
                $next_platform_fee_date = is_null($subscription->next_platform_fee_date)
                    ? now()->addMonth($plan->subscription_period_month)->addDay()
                    : $subscription->next_platform_fee_date->addMonth();
                $enrollment_completed_at = now();
            } elseif ($subscription->installment_type == 1 && $subscription->entity_type == Partner::class) {
                if ($subscription->installments_paid < $plan->total_installments_count) {
                    $next_installment_date = is_null($subscription->next_installment_date)
                        ? now()->addMonth()
                        : $subscription->next_installment_date->addMonth();
                    $currentPhase = Subscription::PHASE_ENROLLMENT;
                } else {
                    $next_platform_fee_date  = now()->addMonth()->addDay();
                    $enrollment_completed_at = now();
                    $currentPhase            = Subscription::PHASE_PLATFORM_FEE;
                }
            }
        }

        $subscription->update([
            'current_phase'          => $currentPhase,
            'enrollment_completed_at' => $enrollment_completed_at,
            'next_installment_date'  => $next_installment_date,
            'next_platform_fee_date' => $next_platform_fee_date,
        ]);
    }
    /**
     * Check if an invoice already exists for the given cycle.
     *
     * @param Subscription $subscription
     * @param bool $isPlatformFee
     * @param string $cycleDate
     * @return bool
     */

    protected function createInvoiceIfDue(Subscription $subscription, bool $isPlatformFee = false): bool
    {
        $today = Carbon::today();

        $nextDue = $isPlatformFee
            ? $subscription->next_platform_fee_date
            : $subscription->next_installment_date;

        if (!$nextDue) {
            return false;
        }

        $nextDue = Carbon::parse($nextDue);

        $generateBeforeDays   = config('subscription.generate_before_days', 5);
        $earliestGenerateDate = $nextDue->copy()->subDays($generateBeforeDays);

        if ($today < $earliestGenerateDate) {
            return false;
        }

        $currentMonth = $today->format('Y-m');
        $unpaidInvoiceExistsThisMonth = Invoice::where('entity_type', $subscription->entity_type)
            ->where('entity_id', $subscription->entity_id)
            ->where('is_platform_fee', $isPlatformFee ? 1 : 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$currentMonth])
            ->exists();

        if ($unpaidInvoiceExistsThisMonth) {
            return false;
        }

        $billingCycleMonth = $nextDue->format('Y-m');
        $paidInvoiceExists = Invoice::where('entity_type', $subscription->entity_type)
            ->where('entity_id', $subscription->entity_id)
            ->where('is_platform_fee', $isPlatformFee ? 1 : 0)
            ->where('status', Invoice::STATUS_PAID)
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$billingCycleMonth])
            ->exists();

        return !$paidInvoiceExists;
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
    protected function createInvoiceForSubscription(Subscription $subscription, string $type): void
    {
        try {
            DB::transaction(function () use ($subscription, $type) {

                $entity = $subscription->entity;

                if (!$entity || $subscription->status !== Subscription::STATUS_ACTIVE) {
                    return;
                }

                if ($subscription->entity_type === Borrower::class) {
                    if ($entity->status !== Borrower::STATUS_ACTIVE) {
                        return;
                    }
                    $entity->loadMissing('partner');
                    $partner = $entity->partner;
                    if (!$partner || $partner->status !== Partner::STATUS_ACTIVE) {
                        return;
                    }
                } elseif ($subscription->entity_type === Partner::class) {
                    if ($entity->status !== Partner::STATUS_ACTIVE) {
                        return;
                    }
                }

                $isPlatformFee = $type === Subscription::PHASE_PLATFORM_FEE;

                $cycleDate = $isPlatformFee
                    ? $subscription->next_platform_fee_date
                    : $subscription->next_installment_date;

                if (!$cycleDate) {
                    return;
                }

                $invoiceCount = Invoice::where('entity_type', $subscription->entity_type)
                    ->where('entity_id', $subscription->entity_id)
                    ->where('is_platform_fee', 0)
                    ->count();

                $totalCount = $subscription->partnerPlan?->total_installments_count;
                $hasDownPayment = $subscription->down_payment && $subscription->down_payment > 0;
                $allowedInvoices = $hasDownPayment ? ($totalCount + 1) : $totalCount;

                if ($totalCount && $invoiceCount >= $allowedInvoices) {
                    return;
                }

                if (!$this->createInvoiceIfDue($subscription, $isPlatformFee)) {
                    return;
                }

                // Installment limit check: switch phase if all installments are done
                if (!$isPlatformFee) {
                    if (!$totalCount || $subscription->installments_paid >= $totalCount) {
                        $subscription->update([
                            'current_phase'         => Subscription::PHASE_PLATFORM_FEE,
                            'next_platform_fee_date' => now()->addMonth()->startOfMonth(),
                        ]);
                        return;
                    }
                }

                $isPartnerPaying = false;

                if ($subscription->entity_type === Borrower::class) {
                    $partnerId = $entity->partner_id;
                } else {
                    $partnerId = $entity->id;
                }

                $amount = $subscription->payable_amount;
                if ($isPlatformFee) {
                    $amount = $subscription->monthly_platform_fee;
                } else {
                    if ($subscription->installment_type == 1 && $subscription->installment_count > 0) {
                        if ($invoiceCount == 0 && $hasDownPayment) {
                            $amount = $subscription->down_payment;
                        } else {
                            $amount = ($subscription->total_subscription_amount - $subscription->down_payment) / $subscription->installment_count;
                        }
                    }
                }

                $invoiceType = Invoice::INVOICE_TYPE_ENROLLMENT;

                if (!$isPlatformFee && $subscription->installment_type == 1) {
                    if ($invoiceCount == 0 && $hasDownPayment) {
                        $invoiceType = Invoice::INVOICE_TYPE_ENROLLMENT;
                    } else {
                        $invoiceType = Invoice::INVOICE_TYPE_INSTALLMENT;
                    }
                }

                $invoice = $this->invoiceService->create([
                    'partner_id'       => $partnerId,
                    'borrower_id'      => $subscription->entity_type === Borrower::class ? $subscription->entity_id : null,
                    'subscription_id'  => $subscription->id,
                    'entity_type'      => $subscription->entity_type,
                    'entity_id'        => $subscription->entity_id,
                    'customer_name'    => $entity->full_name ?? trim(($entity->first_name ?? '') . ' ' . ($entity->last_name ?? '')),
                    'email'            => $entity->email,
                    'country'          => $entity->country ?? null,
                    'payment_amount'   => $amount,
                    'currency'         => Invoice::CURRENCY_USD,
                    'status'           => Invoice::STATUS_UNPAID,
                    'type'             => $invoiceType,
                    'is_partner_paying' => $isPartnerPaying,
                    'is_platform_fee'  => $isPlatformFee ? 1 : 0,
                ]);

                Log::info('Invoice created successfully', ['invoice_id' => $invoice->id ?? null]);

                try {
                    $this->invoiceService->sendInvoice($invoice->id);
                    Log::info('Invoice email sent successfully via batch process', ['invoice_id' => $invoice->id]);
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
                'type'            => $type,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            $emails = explode(',', config('app.cron_notify_emails', ''));
            $emails = array_filter(array_map('trim', $emails));

            if (!empty($emails)) {
                $entity = $subscription->entity;
                $data = [
                    'job_name'      => 'ProcessSubscriptions (subscriptions:process) - Failure',
                    'error_message' => $e->getMessage(),
                    'timestamp'     => now()->toDateTimeString(),
                    'stack_trace'   => $e->getTraceAsString(),
                    'user_email'    => $entity->email ?? 'N/A',
                    'user_id'       => $entity->id ?? 'N/A',
                ];

                if ($subscription->entity_type === Borrower::class) {
                    $data['partner_name'] = $entity->partner?->full_name ?? 'N/A';
                } else {
                    $data['partner_name'] = $entity->full_name ?? 'N/A';
                }

                Mail::to($emails)->send(new CronJobFailureMail($data));
            }
        }
    }

    // Cron Job
    /**
     * Generate invoices for enrollment installment phase.
     *
     * Queries subscriptions table directly using current_phase + next_installment_date.
     */
    public function generateInstallmentInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        Subscription::with(['entity', 'partnerPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('current_phase', Subscription::PHASE_ENROLLMENT)
            ->whereNotNull('next_installment_date')
            ->whereDate('next_installment_date', '<=', $targetDate)
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $this->createInvoiceForSubscription($subscription, Subscription::PHASE_ENROLLMENT);
                }
            });
    }

    // Cron Job
    /**
     * Generate invoices for platform fee phase.
     *
     * Queries subscriptions table directly using current_phase + next_platform_fee_date.
     */
    public function generatePlatformFeeInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        Subscription::with(['entity', 'partnerPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('current_phase', Subscription::PHASE_PLATFORM_FEE)
            ->whereNotNull('next_platform_fee_date')
            ->whereDate('next_platform_fee_date', '<=', $targetDate)
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $this->createInvoiceForSubscription($subscription, Subscription::PHASE_PLATFORM_FEE);
                }
            });
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
