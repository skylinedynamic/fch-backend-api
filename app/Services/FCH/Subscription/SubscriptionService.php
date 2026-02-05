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
use App\Services\FCH\InvoiceService;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;

class SubscriptionService
{
    use PaginatesApi;

    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

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
            ->with(['partner:id,full_name,company_name,email', 'borrower:id,first_name,last_name,email'])
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

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']);

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $skipSorting = false;

        // Join Partners if sorting or filtering by partner_name
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $query->leftJoin('partners', function ($join) {
                $join->on('subscriptions.entity_id', '=', 'partners.id')
                    ->where('subscriptions.entity_type', '=', \App\Models\Partner\Partner::class);
            })->addSelect('partners.full_name as partner_name');

            if ($sortField === 'partner_name') {
                $query->orderBy('partners.full_name', $sortOrder);
                $skipSorting = true;
            }
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
                    'enrollment_type' => 'enrollment_type',
                    'status' => 'status'
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
            $data['installment_type'] = (Str::contains($data['enrollment_type'], ['Payment Plan', 'Monthly'])) ? 1 : 0;
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
        return Subscription::where('entity_type', \App\Models\Partner\Partner::class)
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
        return Subscription::where('entity_type', \App\Models\Borrower\Borrower::class)
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
     * @param string $enrollmentType
     * @param array $additionalData
     * @return Subscription
     */
    public function startSubscription($entity, $plan, string $enrollmentType, array $additionalData = []): Subscription
    {
        $subscription = Subscription::where('entity_type', get_class($entity))
            ->where('entity_id', $entity->id)
            ->first();

        $data = array_merge([
            'subscription_plan_id' => $plan->id ?? null,
            'partner_type' => $plan->partner_type ?? null,
            'enrollment_type' => $enrollmentType,
            'installment_type' => ($plan && $plan->total_installments_count > 1) ? 1 : 0,
            'monthly_platform_fee' => $plan->platform_fee ?? 0,
            'payable_amount' => $plan->payment_amount ?? 0,
            'total_subscription_amount' => $plan->total_amount ?? 0,
            'status' => Subscription::STATUS_PENDING,
        ], $additionalData);

        $subscription = Subscription::updateOrCreate(
            ['entity_type' => get_class($entity), 'entity_id' => $entity->id],
            $data
        );

        $subscription->phases()->create([
            'phase' => SubscriptionPhase::PHASE_ENROLLMENT,
            'installments_paid' => 0,
            'next_installment_date' => null,
            'next_platform_fee_date' => null,
        ]);

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

        $phase = $subscription->phases()->first();
        if (!$phase) {
            $phase = $subscription->phases()->create([
                'phase' => SubscriptionPhase::PHASE_ENROLLMENT,
                'installments_paid' => 0,
            ]);
        }

        $plan = $subscription->partnerPlan;
        $totalInstallments = $plan->total_installments_count ?? 1;
        $periodMonths = $plan->subscription_period_month ?? $totalInstallments;

        // PLATFORM FEE PAYMENT
        if ($invoice->is_platform_fee && $subscription->entity_type == \App\Models\Partner\Partner::class) {
            $phase->update([
                'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE,
                'next_platform_fee_date' => now()->addMonth()->addDay(),
            ]);
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
            return;
        }

        // ENROLLMENT PAYMENT
        $invoiceCount = Invoice::where('entity_type', $invoice->entity_type)
            ->where('entity_id', $invoice->entity_id)
            ->where('is_platform_fee', false)
            ->where('status', Invoice::STATUS_PAID)
            ->get()->count();
        $phase->installments_paid = $invoiceCount;

        $phase->save();

        // Set subscription start / end / platform fee start dates if first payment
        if (!$subscription->subscription_start_date) {
            $subscription->update([
                'subscription_start_date' => now(),
                'first_payment_date' => now(),
                'subscription_end_date' => $plan->subscription_period_month ? now()->addMonths($periodMonths) : null,
                'platform_fee_start_date' => $plan->subscription_period_month ? now()->addMonths($periodMonths)->addDay() : null,
                'status' => Subscription::STATUS_ACTIVE,
            ]);
        } else {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        // Complete enrollment if last installment or one-time payment
        // if ($phase->installments_paid >= $totalInstallments) {
        $this->completeEnrollment($subscription, $phase, $plan);
        // }
    }

    /**
     * Complete enrollment and schedule first platform fee invoice.
     *
     * @param Subscription $subscription
     * @param SubscriptionPhase $phase
     * @return void
     */
    private function completeEnrollment(Subscription $subscription, SubscriptionPhase $phase, $plan)
    {
        $next_installment_date = null;
        $next_platform_fee_date = null;
        $enrollment_completed_at = null;
        $phaseFee  = SubscriptionPhase::PHASE_PLATFORM_FEE;

        Log::info($plan->entity_type == Borrower::class);
        if ($plan->entity_type == Borrower::class) {
            Log::info($subscription->installment_type);
            Log::info('ARafat');
            Log::info($subscription->installment_type == 1);
            if ($subscription->installment_type == 1 && $phase->installments_paid < $plan->total_installments_count) {
                $next_installment_date = $phase->next_installment_date ? $phase->next_installment_date->addMonth() : $subscription->subscription_start_date->addMonth();
                $next_platform_fee_date = null;
                $phaseFee  = SubscriptionPhase::PHASE_ENROLLMENT;
                $enrollment_completed_at = null;
            } else {
                $next_installment_date = null;
                $next_platform_fee_date = null;
                $phaseFee  = SubscriptionPhase::PHASE_ENROLLMENT;
                $enrollment_completed_at = now();
            }
        } else {
            $next_installment_date = null;
            $next_platform_fee_date = $subscription->platform_fee_start_date ?: now()->copy()->addMonth()->addDay();
            $phaseFee  = SubscriptionPhase::PHASE_PLATFORM_FEE;
            $enrollment_completed_at = now();
        }
        $phase->update([
            'phase' => $phaseFee,
            'enrollment_completed_at' => $enrollment_completed_at,
            'next_installment_date' => $next_installment_date,
            'next_platform_fee_date' => $next_platform_fee_date,
        ]);

        $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
    }
    /**
     * Check if an invoice already exists for the given cycle.
     *
     * @param Subscription $subscription
     * @param bool $isPlatformFee
     * @param string $cycleDate
     * @return bool
     */

    protected function invoiceExistsForCycle(Subscription $subscription, bool $isPlatformFee, string $cycleDate): bool
    {
        return Invoice::where('entity_type', $subscription->entity_type)
            ->where('entity_id', $subscription->entity_id)
            ->where('is_platform_fee', $isPlatformFee ? 1 : 0)
            ->whereYear('created_at', Carbon::parse($cycleDate)->year)
            ->whereMonth('created_at', Carbon::parse($cycleDate)->month)
            ->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_UNPAID])
            ->exists();
    }

    /**
     * Create invoice for a phase (enrollment / platform fee)
     *
     * @param SubscriptionPhase $phase
     * @param string $type
     * @return void
     */

    protected function createInvoiceForPhase(SubscriptionPhase $phase, string $type): void
    {
        DB::transaction(function () use ($phase, $type) {

            $subscription = $phase->subscription;
            $entity = $subscription->entity;

            if (!$entity || $subscription->status !== 'Active') {
                return;
            }

            $isPlatformFee = $type === 'platform_fee';

            $cycleDate = $isPlatformFee
                ? $phase->next_platform_fee_date
                : $phase->next_installment_date;

            if (!$cycleDate) {
                return;
            }

            $entityType = $subscription->entity_type;
            $entityId = $subscription->entity_id;

            $invoiceCount = Invoice::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('is_platform_fee', 0)
                ->count();

            $totalCount = $subscription->partnerPlan?->total_installments_count;
            Log::info($totalCount && $invoiceCount >= $totalCount);
            Log::info($totalCount);
            Log::info($invoiceCount >= $totalCount);
            Log::info($invoiceCount  . ' ' . $totalCount);

            if ($totalCount && $invoiceCount >= $totalCount) {
                return;
            }

            // Prevent duplicate (paid OR unpaid) for this month
            if ($this->invoiceExistsForCycle($subscription, $isPlatformFee, $cycleDate)) {
                return;
            }

            // Installment limit check (Secondary check using phase state)
            if (!$isPlatformFee) {
                if (!$totalCount || $phase->installments_paid >= $totalCount) {
                    // Switch to platform fee phase
                    $phase->update([
                        'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE,
                        'next_platform_fee_date' => now()->addMonth()->startOfMonth(),
                    ]);
                    return;
                }
            }

            // Determine who pays
            $isPartnerPaying = false;

            if ($subscription->entity_type === Borrower::class) {
                $entity->loadMissing('partner');
                $isPartnerPaying = $entity->partner?->is_host != 1;
                $partnerId = $entity->partner_id;
            } else {
                $isPartnerPaying = $entity->is_host != 1;
                $partnerId = $entity->id;
            }

            $amount = $isPlatformFee
                ? $subscription->monthly_platform_fee
                : $subscription->payable_amount;

            // Create invoice
            $this->invoiceService->create([
                'partner_id' => $partnerId,
                'borrower_id' => $subscription->entity_type === Borrower::class
                    ? $subscription->entity_id
                    : null,
                'entity_type' => $subscription->entity_type,
                'entity_id' => $subscription->entity_id,
                'customer_name' => $entity->full_name ?? trim(($entity->first_name ?? '') . ' ' . ($entity->last_name ?? '')),
                'email' => $entity->email,
                'payment_amount' => $amount,
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => $isPartnerPaying,
                'cycle' => Invoice::CYCLE_MONTHLY,
                'is_platform_fee' => $isPlatformFee ? 1 : 0,
            ]);

            // Update phase AFTER invoice
            // if (!$isPlatformFee) {
            //     $phase->increment('installments_paid');
            //     $phase->update([
            //         'next_installment_date' => Carbon::parse($cycleDate)->addMonth()->toDateString(),
            //     ]);
            // } else {
            //     $phase->update([
            //         'next_platform_fee_date' => Carbon::parse($cycleDate)->addMonth()->toDateString(),
            //     ]);
            // }
        });
    }
    
    // Cron Job Start
    /**
     * Generate invoices for installment phase.
     */
    public function generateInstallmentInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        SubscriptionPhase::with(['subscription.entity', 'subscription.partnerPlan'])
            ->where('phase', SubscriptionPhase::PHASE_ENROLLMENT)
            ->whereNotNull('next_installment_date')
            ->whereDate('next_installment_date', '<=', $targetDate)
            ->whereHas('subscription', fn($q) => $q->where('status', 'Active'))
            ->chunkById(100, function ($phases) {
                foreach ($phases as $phase) {
                    $this->createInvoiceForPhase($phase, 'enrollment');
                }
            });
    }

    // Cron Job Start
    /**
     * Generate invoices for platform fee phase.
     */
    public function generatePlatformFeeInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        SubscriptionPhase::with(['subscription.entity'])
            ->where('phase', SubscriptionPhase::PHASE_PLATFORM_FEE)
            ->whereNotNull('next_platform_fee_date')
            ->whereDate('next_platform_fee_date', '<=', $targetDate)
            ->whereHas('subscription', fn($q) => $q->where('status', 'Active'))
            ->chunkById(100, function ($phases) {
                foreach ($phases as $phase) {
                    $this->createInvoiceForPhase($phase, 'platform_fee');
                }
            });
    }
}
