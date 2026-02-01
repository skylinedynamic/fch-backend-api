<?php

namespace App\Services\FCH\Subscription;

use Illuminate\Support\Str;
use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription\Subscription;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;

use App\Models\Invoice\Invoice;
use App\Models\Subscription\SubscriptionPhase;
use App\Services\FCH\InvoiceService;

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
            Log::warning('Subscription not found for payment handling.', [
                'invoice_id' => $invoice->id,
                'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id,
            ]);
            return;
        }

        $plan = $subscription->partnerPlan; // Subscription model has partnerPlan() relation

        // Find the singleton phase record for this subscription
        $phase = $subscription->phases()->first();

        if (!$phase) {
            $phase = $subscription->phases()->create([
                'phase' => $invoice->is_platform_fee ? SubscriptionPhase::PHASE_PLATFORM_FEE : SubscriptionPhase::PHASE_ENROLLMENT,
                'installments_paid' => 0,
            ]);
        }

        // 1️⃣ Platform fee payment handling
        if ($invoice->is_platform_fee) {
            $phase->update([
                'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE,
                'next_platform_fee_date' => now()->addMonth()
            ]);

            // Sync with main subscription table
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'platform_fee_start_date' => $phase->next_platform_fee_date
            ]);
            return;
        }

        // 2️⃣ Enrollment payment (already have the $phase record)

        // ONE TIME PLAN
        if (!$plan || is_null($plan->total_installments_count) || $plan->total_installments_count <= 1) {
            $phase->update(['installments_paid' => 1]);
            $this->completeEnrollment($subscription, $phase);
        } else {
            $phase->installments_paid += 1;

            // Advance the next installment date safely
            if ($phase->next_installment_date) {
                $phase->next_installment_date = $phase->next_installment_date->addMonth();
            } else {
                $phase->next_installment_date = now()->addMonth();
            }

            if ($phase->installments_paid >= $plan->total_installments_count) {
                $this->completeEnrollment($subscription, $phase);
            }

            $phase->save();
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * Complete enrollment phase and transition to platform fee phase.
     *
     * @param Subscription $subscription
     * @param SubscriptionPhase $phase
     * @return void
     */
    private function completeEnrollment(Subscription $subscription, SubscriptionPhase $phase)
    {
        // Set platform fee to start on the 1st of the NEXT month for a consistent rhythm
        $nextDate = now()->addMonth()->startOfMonth();

        // Update the EXISTING phase record to transition to platform fee
        $phase->update([
            'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE,
            'enrollment_completed_at' => now(),
            'next_installment_date' => null,
            'next_platform_fee_date' => $nextDate,
        ]);

        // Generate first platform fee invoice immediately
        $this->createInvoiceForPhase($phase, 'platform_fee');

        // Update main subscription table
        $subscription->update([
            'platform_fee_start_date' => $phase->next_platform_fee_date,
            'monthly_platform_fee' => $subscription->partnerPlan->platform_fee ?? $subscription->monthly_platform_fee,
        ]);
    }

    /**
     * Start a subscription for an entity.
     *
     * @param mixed $entity
     * @param mixed $plan
     * @param string $enrollmentType
     * @param array $additionalData
     * @return Subscription
     */
    public function startSubscription($entity, $plan, $enrollmentType, array $additionalData = [])
    {
        $subscriptionData = array_merge([
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'subscription_plan_id' => $plan->id ?? null,
            'enrollment_type' => $enrollmentType,
            'installment_type' => ($plan && $plan->total_installments_count > 1) ? 1 : 0,
            'monthly_platform_fee' => $plan->platform_fee ?? 0,
            'payable_amount' => $plan->payment_amount ?? 0,
            'total_subscription_amount' => $plan->total_amount ?? 0,
            'subscription_start_date' => now(),
            'status' => Subscription::STATUS_PENDING,
        ], $additionalData);

        $subscription = Subscription::updateOrCreate(
            [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id,
            ],
            $subscriptionData
        );

        // Create initial enrollment phase
        $subscription->phases()->firstOrCreate(
            ['phase' => SubscriptionPhase::PHASE_ENROLLMENT],
            [
                'installments_paid' => 0,
                'next_installment_date' => ($plan && $plan->total_installments_count > 1) ? now()->addMonth() : null,
            ]
        );

        return $subscription;
    }

    /**
     * Process monthly installments for all active subscriptions.
     * This should be called daily via cronjob.
     */
    public function generateInstallmentInvoices()
    {
        $today = now()->toDateString();

        $phases = SubscriptionPhase::where('phase', SubscriptionPhase::PHASE_ENROLLMENT)
            ->whereNotNull('next_installment_date')
            ->where('next_installment_date', '<=', $today)
            ->whereHas('subscription', function ($query) {
                $query->where('status', Subscription::STATUS_ACTIVE);
            })
            ->get();


        foreach ($phases as $phase) {
            $invoice = $this->createInvoiceForPhase($phase, 'enrollment');

            if ($invoice) {
                // Advance the schedule for the next month
                $phase->update([
                    'next_installment_date' => $phase->next_installment_date ? $phase->next_installment_date->addMonth() : now()->addMonth()
                ]);
            }
        }
    }

    /**
     * Process monthly platform fees for all active subscriptions.
     * This should be called daily via cronjob.
     */
    public function generatePlatformFeeInvoices()
    {
        $today = now()->toDateString();

        $phases = SubscriptionPhase::where('phase', SubscriptionPhase::PHASE_PLATFORM_FEE)
            ->whereNotNull('next_platform_fee_date')
            ->where('next_platform_fee_date', '<=', $today)
            ->whereHas('subscription', function ($query) {
                $query->where('status', Subscription::STATUS_ACTIVE);
            })
            ->get();

        foreach ($phases as $phase) {
            $invoice = $this->createInvoiceForPhase($phase, 'platform_fee');

            if ($invoice) {
                // Advance the schedule for the next month
                $phase->update([
                    'next_platform_fee_date' => $phase->next_platform_fee_date ? $phase->next_platform_fee_date->addMonth() : now()->addMonth()
                ]);
            }
        }
    }

    /**
     * Create an invoice for a specific phase.
     */
    protected function createInvoiceForPhase(SubscriptionPhase $phase, string $type)
    {
        $subscription = $phase->subscription;
        $entity = $subscription->entity; // Relationship to Partner or Borrower

        if (!$entity) return;

        $isPlatformFee = ($type === 'platform_fee');

        // Prevent multiple invoices in one month even if catching up from very old dates
        $createdInMonth = Invoice::where('entity_type', $subscription->entity_type)
            ->where('entity_id', $subscription->entity_id)
            ->where('is_platform_fee', $isPlatformFee ? 1 : 0)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->exists();

        if ($createdInMonth) {
            Log::info("ℹ️ Invoice already generated this month for {$type}, skipping.", [
                'subscription_id' => $subscription->id,
                'entity_id' => $subscription->entity_id
            ]);
            return;
        }

        $amount = $isPlatformFee ? $subscription->monthly_platform_fee : $subscription->payable_amount;

        $invoiceData = [
            'entity_type' => $subscription->entity_type,
            'entity_id' => $subscription->entity_id,
            'partner_id' => ($subscription->entity_type === Partner::class) ? $subscription->entity_id : ($entity->partner_id ?? null),
            'borrower_id' => ($subscription->entity_type === Borrower::class) ? $subscription->entity_id : null,
            'customer_name' => ($subscription->entity_type === Partner::class) ? $entity->full_name : ($entity->first_name . ' ' . $entity->last_name),
            'email' => $entity->email,
            'payment_amount' => $amount,
            'status' => Invoice::STATUS_UNPAID,
            'cycle' => Invoice::CYCLE_MONTHLY,
            'is_platform_fee' => $isPlatformFee ? 1 : 0,
        ];

        try {
            $invoice = $this->invoiceService->create($invoiceData);
            Log::info("✅ Recurring Invoice Created for {$type}", [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->invoice_id,
                'amount' => $amount
            ]);
            return $invoice;
        } catch (\Exception $e) {
            Log::error("❌ Failed to create recurring invoice for {$type}", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
