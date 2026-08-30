<?php

namespace App\Services\FCH\Subscription;

use App\Mail\Payment\PaymentSuccessUserMail;
use App\Models\User\User;
use Illuminate\Support\Facades\Hash;
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
use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Subscription\SubscriptionListResource;
use App\Http\Resources\FCH\Subscription\SubscriptionDetailsResource;
use App\Http\Resources\FCH\Subscription\PartnerBorrowerFeeResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

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
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters, ?int $partnerId = null, ?int $borrowerId = null)
    {
        $query = $this->prepareSubscriptionQuery($filters, $partnerId, $borrowerId);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'));
    }

    /**
     * Apply RBAC / user scope to subscription query.
     */
    private function applyRbacScope(Builder $query): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $partner = $user->partner;
        $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : true;

        if (!$isHost && $user->partner_id) {
            // Non-host partner sees only his clients' (borrowers') subscriptions
            $userPartnerId = $user->partner_id;
            $query->where('subscriptions.entity_type', Borrower::class)
                ->whereExists(function ($sub) use ($userPartnerId) {
                    $sub->select(DB::raw(1))
                        ->from('borrowers')
                        ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                        ->where('borrowers.partner_id', $userPartnerId);
                });
        }
    }

    /**
     * Fetch All Subscriptions (Global).
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchAll(array $filters)
    {
        $query = $this->prepareSubscriptionQuery($filters, null, null);
        $this->applyRbacScope($query);
        $stats = $this->getStatsFromQuery(clone $query);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'), ['stats' => $stats]);
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
                'user:id,name,email',
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

        // Join Enrollment Phase for next_installment_date or enrollment_completed_at
        if ($sortField === 'next_installment_date' || isset($rawFilters['next_installment_date']) || $sortField === 'enrollment_completed_at' || isset($rawFilters['enrollment_completed_at'])) {
            $query->withEnrollmentPhase();

            if ($sortField === 'next_installment_date') {
                $query->orderBy('ep.next_installment_date', $sortOrder);
                $skipSorting = true;
            } elseif ($sortField === 'enrollment_completed_at') {
                $query->orderBy('ep.enrollment_completed_at', $sortOrder);
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
                    'subscriber_name' => 'partners.full_name',
                    'partner_name' => 'partners.full_name',
                    'entity_type' => 'subscriptions.entity_type',
                    'enrollment_type' => 'subscriptions.enrollment_type',
                    'payable_amount' => 'subscriptions.payable_amount',
                    'status' => 'subscriptions.status',
                    'created_at' => 'subscriptions.created_at',
                    'subscription_start_date' => 'subscriptions.subscription_start_date',
                    'subscription_end_date' => 'subscriptions.subscription_end_date',
                    'expected_end_date' => 'subscriptions.expected_end_date',
                    'enrollment_completed_at' => 'ep.enrollment_completed_at',
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
    private function paginateAndRespond($query, array $filters, string $message, array $additionalData = [])
    {
        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            SubscriptionListResource::class,
            $message,
            200,
            $additionalData
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
        $query = Subscription::with([
            'partner',
            'borrower',
            'borrower.partner',
            'user',
            'partnerPlan',
            'enrollmentPhase',
            'platformFeePhase'
        ])->where('id', $id);

        $this->applyRbacScope($query);

        return $query->first();
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
     * @param Subscription $subscription
     * @return void
     */
    public function handleSuccessfulPayment(Invoice $invoice, ?Subscription $subscription)
    {
        // When invoice status is Processing, do not update or start the subscription (invoice is not paid yet)
        if (
            $invoice->status === Invoice::STATUS_PROCESSING ||
            strtolower((string) $invoice->status) === strtolower(Invoice::STATUS_PROCESSING)
        ) {
            Log::info('Invoice status is Processing, skipping subscription start/update', [
                'invoice_id' => $invoice->id,
                'status' => $invoice->status,
            ]);
            return;
        }

        if (!$subscription) {
            Log::warning('Subscription not found for payment', ['invoice_id' => $invoice->id]);
            return;
        }

        // Activate partner or borrower status upon successful payment
        $entity = $subscription->entity;

        if ($entity) {
            if ($entity instanceof Partner && $entity->status !== Partner::STATUS_ACTIVE) {
                $entity->update(['status' => Partner::STATUS_ACTIVE]);
            } elseif ($entity instanceof Borrower && $entity->status !== Borrower::STATUS_ACTIVE) {
                $entity->update(['status' => Borrower::STATUS_ACTIVE]);
            }
        }

        $enrollmentType = $subscription->enrollment_type;

        if (
            $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT
            || $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG
        ) {
            $this->handleOneTimePayment($subscription, $invoice);
            return;
        }

        if (
            $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT
            || $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG
        ) {
            $this->handleInstallmentPayment($subscription, $invoice);
            return;
        }

        if (
            $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE
            || $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE_SLUG
            || $subscription->entity_type === Employee::class
            || $subscription->entity_type === User::class
        ) {
            $this->handleUserSeatPayment($subscription, $invoice);
            return;
        }
    }


    /**
     * Handle payment for One Time Payment enrollment.
     *
     * @param Subscription $subscription
     * @param Invoice $invoice
     * @return void
     */
    private function handleOneTimePayment(Subscription $subscription, Invoice $invoice): void
    {
        $isFirstPayment = !$subscription->subscription_start_date;
        $isBorrower = $subscription->entity_type === Borrower::class;

        $updateData = [
            'installments_paid' => 1,
            'enrollment_completed_at' => $subscription->enrollment_completed_at ?: now(),
            'current_cycle_invoice_id' => null,
        ];

        if ($isFirstPayment) {
            $updateData['subscription_start_date'] = now();
            $updateData['first_payment_date'] = now();
        }

        if ($isBorrower) {
            // Borrowers only ever owe the one-time enrollment amount — nothing recurs
            // after this. subscription_end_date records the date they're paid through
            // (today, since this is their only payment ever); expected_end_date is
            // cleared since nothing further is ever scheduled.
            $updateData['status'] = Subscription::STATUS_ACTIVE;
            $updateData['current_phase'] = Subscription::PHASE_COMPLETED;
            $updateData['subscription_end_date'] = now()->toDateString();
            $updateData['expected_end_date'] = null;
        } else {
            // Partners transition into the recurring monthly platform-fee cycle.
            // Catch up subscription_end_date (paid through) to whatever cycle was
            // scheduled — normally "now" on a first/on-time payment, or a past
            // cycle date if this payment is catching up after a rollover — then
            // schedule expected_end_date one cycle further out.
            $caughtUpTo = $subscription->expected_end_date
                ? Carbon::parse($subscription->expected_end_date)
                : now();

            $updateData['status'] = Subscription::STATUS_ACTIVE;
            $updateData['current_phase'] = Subscription::PHASE_PLATFORM_FEE;
            $updateData['subscription_end_date'] = $caughtUpTo->toDateString();
            $updateData['expected_end_date'] = $caughtUpTo->copy()->addMonth()->toDateString();
        }

        $subscription->update($updateData);
    }

    /**
     * Handle payment for Installment enrollment.
     *
     * @param Subscription $subscription
     * @param Invoice $invoice
     * @return void
     */
    private function handleInstallmentPayment(Subscription $subscription, Invoice $invoice): void
    {
        // Catch up subscription_end_date (paid through) to whatever cycle was
        // scheduled in expected_end_date — normally "now" on a first/on-time
        // payment, or a past cycle date if this payment is catching up after a
        // rollover — then schedule expected_end_date one cycle further out.
        $caughtUpTo = $subscription->expected_end_date
            ? Carbon::parse($subscription->expected_end_date)
            : now();
        $nextExpected = $caughtUpTo->copy()->addMonth();
        $plan = $subscription->partnerPlan;

        // Set start date on first payment. subscription_start_date is only ever
        // written by this branch, so its absence alone reliably means "this is the
        // very first payment" — whether that payment was a down payment (current_phase
        // starts at PHASE_ENROLLMENT) or the first installment on a no-down-payment
        // plan (current_phase starts at PHASE_INSTALLMENT). Either way it migrates
        // the subscription into PHASE_INSTALLMENT below.
        if (!$subscription->subscription_start_date) {
            $subscription->update([
                'subscription_start_date' => now(),
                'first_payment_date' => now(),
                'installments_paid' => $invoice->type == Invoice::INVOICE_TYPE_DOWN_PAYMENT ? 0 : 1,
                'status' => Subscription::STATUS_ACTIVE,
                'current_phase' => Subscription::PHASE_INSTALLMENT,
                'subscription_end_date' => $caughtUpTo->toDateString(),
                'expected_end_date' => $nextExpected->toDateString(),
                'current_cycle_invoice_id' => null,
            ]);

        } elseif ($subscription->current_phase == Subscription::PHASE_INSTALLMENT) {

            $isLastInstallment = $subscription->installment_count == ($subscription->installments_paid + 1);
            $isBorrower = $subscription->entity_type === Borrower::class;

            $updateData = [
                'installments_paid' => $subscription->installments_paid + 1,
                'installment_end_date' => $nextExpected->toDateString(),
                'enrollment_completed_at' => $isLastInstallment ? now() : null,
                'subscription_end_date' => $caughtUpTo->toDateString(),
                'current_cycle_invoice_id' => null,
            ];

            if ($isLastInstallment && $isBorrower) {
                // Borrower's final installment paid off — nothing recurs after this.
                // Status stays Active; current_phase records "nothing left to bill."
                // expected_end_date is cleared since nothing further is scheduled.
                $updateData['status'] = Subscription::STATUS_ACTIVE;
                $updateData['current_phase'] = Subscription::PHASE_COMPLETED;
                $updateData['expected_end_date'] = null;
            } else {
                $updateData['status'] = Subscription::STATUS_ACTIVE;
                $updateData['expected_end_date'] = $nextExpected->toDateString();
                $updateData['current_phase'] = $isLastInstallment ? Subscription::PHASE_PLATFORM_FEE : Subscription::PHASE_INSTALLMENT;
            }

            $subscription->update($updateData);
        } else {
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'installment_end_date' => $nextExpected->toDateString(),
                'subscription_end_date' => $caughtUpTo->toDateString(),
                'expected_end_date' => $nextExpected->toDateString(),
                'current_cycle_invoice_id' => null,
            ]);
        }

        return;
    }

    /**
     * Handle payment for Monthly / User Seat enrollment.
     *
     * @param Subscription $subscription
     * @param Invoice $invoice
     * @return void
     */
    private function handleUserSeatPayment(Subscription $subscription, Invoice $invoice): void
    {
        $monthlyFee = $subscription->per_seat_amount ?: 49.97;
        $monthsPaid = $invoice->month_count ?? 1;

        $isFirstActivation = !$subscription->subscription_start_date;

        // Resolve user record
        $user = null;
        if ($subscription->entity_type === User::class || empty($subscription->entity_type)) {
            $user = User::find($subscription->entity_id);
        } else {
            $user = User::where('entity_type', Employee::class)
                ->where('entity_id', $subscription->entity_id)
                ->first();
        }

        if ($isFirstActivation) {
            $plainPassword = bin2hex(random_bytes(4));
            $newEnd = now()->addMonths($monthsPaid);

            if ($user) {
                $user->update([
                    'status' => User::STATUS['active'],
                    'password' => Hash::make($plainPassword),
                ]);

                try {
                    Mail::to($user->email)->queue(new PaymentSuccessUserMail([
                        'amount' => $invoice->payment_amount,
                        'currency' => $invoice->currency,
                        'transaction_id' => 'SUB_' . $subscription->id,
                        'description' => 'User seat activation',
                        'date' => now(),
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'plainPassword' => $plainPassword,
                        'user' => $user,
                    ], $user->partner_id));
                } catch (\Exception $e) {
                    Log::error('Failed to send Seat Purchase Mail: ' . $e->getMessage());
                }
            }

            // User-seat subscriptions don't roll forward on a missed cycle (they still
            // freeze to Past Due, unchanged), so subscription_end_date and
            // expected_end_date never diverge for this phase — keep them in lockstep.
            $subscription->update([
                'subscription_start_date' => now(),
                'first_payment_date' => now(),
                'subscription_end_date' => $newEnd,
                'expected_end_date' => $newEnd,
                'status' => Subscription::STATUS_ACTIVE,
                'current_phase' => Subscription::PHASE_USER_SEAT,
            ]);
        } else {
            // Renewal: extend subscription_end_date (kept in sync with expected_end_date)
            $currentEnd = Carbon::parse($subscription->subscription_end_date);
            $newEnd = $currentEnd->addMonths($monthsPaid);

            $subscription->update([
                'subscription_end_date' => $newEnd,
                'expected_end_date' => $newEnd,
                'status' => Subscription::STATUS_ACTIVE,
            ]);

            if ($user) {
                $user->update([
                    'status' => User::STATUS['active'],
                ]);
            }
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
        $dueDate = $subscription->expected_end_date;

        if (!$dueDate) {
            return false;
        }

        $parsedDueDate = Carbon::parse($dueDate)->startOfDay();
        $generateBeforeDays = (int) config('subscription.generate_before_days', 5);
        $earliestGenerateDate = $parsedDueDate->copy()->subDays($generateBeforeDays)->startOfDay();

        // Too early to do anything for this cycle at all.
        if ($today->lt($earliestGenerateDate)) {
            return false;
        }

        // Is there an invoice already open for the current cycle?
        if ($subscription->current_cycle_invoice_id) {
            $currentInvoice = Invoice::find($subscription->current_cycle_invoice_id);

            if ($currentInvoice && $currentInvoice->status === Invoice::STATUS_UNPAID) {
                // Give it the SAME grace window a normal on-time cycle gets before the
                // next one is generated — i.e. up to generate_before_days before what
                // the following cycle's due date would be. A payer who's a few days
                // late still pays this exact invoice; nothing rolls yet.
                $nextCycleDate = $parsedDueDate->copy()->addMonth();
                $nextEarliestGenerateDate = $nextCycleDate->copy()->subDays($generateBeforeDays)->startOfDay();

                if ($today->lt($nextEarliestGenerateDate)) {
                    // Still within grace — keep this invoice live, don't generate
                    // another one. Auto-charge retries keep running against it in
                    // the meantime (Step 2 re-queries all unpaid invoices every run).
                    return false;
                }

                // Grace exhausted — roll forward for recurring phases only.
                $isRecurringPhase = in_array($subscription->current_phase, [
                    Subscription::PHASE_INSTALLMENT,
                    Subscription::PHASE_PLATFORM_FEE,
                    Subscription::PHASE_USER_SEAT,
                ], true);

                if ($isRecurringPhase) {
                    // Expire the missed invoice and advance to the next cycle. The
                    // subscription stays Active and keeps billing every cycle
                    // regardless of a missed payment — it never permanently dies in
                    // Past Due. installments_paid/seat counters are NOT incremented
                    // here (only a real payment does that), so an installment plan
                    // simply takes longer to complete if cycles are missed —
                    // nothing is silently forgiven. Applies to PHASE_INSTALLMENT,
                    // PHASE_PLATFORM_FEE and PHASE_USER_SEAT alike.
                    $currentInvoice->update(['status' => Invoice::STATUS_EXPIRED]);

                    // Only expected_end_date moves — subscription_end_date (paid
                    // through) stays exactly where the last real payment left it.
                    // That gap is, by construction, how far behind they are.
                    $subscription->update([
                        'expected_end_date' => $nextCycleDate->toDateString(),
                        'current_cycle_invoice_id' => null,
                    ]);

                    Log::info('Rolled subscription to next cycle after a missed payment', [
                        'subscription_id' => $subscription->id,
                        'expired_invoice_id' => $currentInvoice->id,
                        'new_expected_end_date' => $nextCycleDate->toDateString(),
                    ]);

                    // A fresh cycle is due right now — generate it immediately
                    // rather than re-evaluating the window against the old date.
                    return true;
                }

                // Any non-recurring phase reaching this state (e.g. enrollment,
                // which shouldn't have an expected_end_date/cycle invoice to begin
                // with) keeps the original behavior: mark Past Due and stop being
                // auto-billed.
                $subscription->update(['status' => Subscription::STATUS_PAST_DUE]);

                return false;
            }
        }

        // Deduplication fallback: do not create if an unpaid invoice already exists for this subscription
        $hasUnpaidInvoice = Invoice::where('subscription_id', $subscription->id)
            ->where('status', Invoice::STATUS_UNPAID)
            ->exists();

        if ($hasUnpaidInvoice) {
            return false;
        }

        // Deduplication fallback: do not create if an invoice was already generated within this cycle window
        $alreadyGeneratedThisCycle = Invoice::where('subscription_id', $subscription->id)
            ->where('created_at', '>=', $earliestGenerateDate)
            ->exists();

        return !$alreadyGeneratedThisCycle;
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
                    if ($entity->status !== Borrower::STATUS_ACTIVE)
                        return;
                    $entity->loadMissing('partner');
                    $partner = $entity->partner;
                    if (!$partner || $partner->status !== Partner::STATUS_ACTIVE)
                        return;
                } elseif ($subscription->entity_type === Partner::class) {
                    if ($entity->status !== Partner::STATUS_ACTIVE)
                        return;
                } elseif ($subscription->entity_type === User::class) {
                    if ($entity->status !== User::STATUS['active'])
                        return;
                } elseif ($subscription->entity_type === Employee::class) {
                    $userRecord = User::where('entity_type', Employee::class)->where('entity_id', $subscription->entity_id)->first();
                    if (!$userRecord || $userRecord->status !== User::STATUS['active'])
                        return;
                }

                // Safety net: a Borrower should never be in the recurring platform-fee
                // cycle — self-heal any stale/corrupted state instead of billing $0.
                // Status stays Active; current_phase is what records "nothing left to bill."
                if ($subscription->entity_type === Borrower::class && $subscription->current_phase === Subscription::PHASE_PLATFORM_FEE) {
                    // Only clear expected_end_date (stop scheduling) — leave
                    // subscription_end_date alone, it's an accurate historical record
                    // of when they actually last paid.
                    $subscription->update([
                        'current_phase' => Subscription::PHASE_COMPLETED,
                        'expected_end_date' => null,
                    ]);
                    Log::warning('Auto-completed a Borrower subscription stuck in platform_fee phase', [
                        'subscription_id' => $subscription->id,
                    ]);
                    return;
                }

                if (!$this->createInvoiceIfDue($subscription)) {
                    return;
                }

                $phase = $subscription->current_phase;
                $isPlatformFee = $phase === Subscription::PHASE_PLATFORM_FEE;

                // Installment limit check — keyed on actual payments collected
                // (installments_paid), not raw invoice row count. A missed cycle now
                // generates an Expired row that's rolled past, not a real payment, so
                // counting rows here would stop billing before the plan is actually
                // paid off.
                if ($phase === Subscription::PHASE_INSTALLMENT) {
                    $totalCount = $subscription->installment_count ?? ($subscription->partnerPlan?->total_installment_count_upto ?? 0);
                    $paidCount = (int) ($subscription->installments_paid ?? 0);

                    if ($totalCount && $paidCount >= $totalCount) {
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
                    // User/Employee seat renewal — billed at the per-seat rate, not
                    // monthly_platform_fee (which only applies to Partner subscriptions).
                    $amount = $subscription->per_seat_amount;
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
                $email = $entity->email;

                // For user seat: bill the partner
                if ($isUserSeatPhase) {
                    $partner = Partner::find($entity->partner_id);
                    $customerName = $partner ? ($partner->company_name ?: $partner->full_name) : $customerName;
                    $email = $partner ? $partner->email : $email;
                }

                // Never generate a zero/invalid-amount invoice — a $0 charge attempt on
                // Stripe fails and spams the admin failure-notification mailbox every cycle.
                if ($amount === null || (float) $amount <= 0) {
                    Log::warning('Skipped invoice generation: zero/invalid amount', [
                        'subscription_id' => $subscription->id,
                        'phase' => $phase,
                        'amount' => $amount,
                    ]);
                    return;
                }

                // When creating a new invoice, if previous invoice is still unpaid make that invoice Expired
                Invoice::where('subscription_id', $subscription->id)
                    ->where('id', $subscription->current_cycle_invoice_id)
                    ->where('status', Invoice::STATUS_UNPAID)
                    ->update(['status' => Invoice::STATUS_EXPIRED]);

                $invoice = $this->invoiceService->create([
                    'partner_id' => $partnerId,
                    'borrower_id' => $subscription->entity_type === Borrower::class ? $subscription->entity_id : null,
                    'subscription_id' => $subscription->id,
                    'entity_type' => $isUserSeatPhase ? Partner::class : $subscription->entity_type,
                    'entity_id' => $isUserSeatPhase ? $entity->partner_id : $subscription->entity_id,
                    'customer_name' => $customerName,
                    'email' => $email,
                    'country' => $entity->country ?? null,
                    'payment_amount' => $amount,
                    'currency' => Invoice::CURRENCY_USD,
                    'status' => Invoice::STATUS_UNPAID,
                    'type' => $phase,  // mirrors Subscription::PHASE_* directly
                    'month_count' => 1,
                    'is_partner_paying' => $isPartnerPaying ? 1 : 0,
                    'is_platform_fee' => $isPlatformFee ? 1 : 0,
                ]);

                // Update current cycle invoice ID
                $subscription->update([
                    'current_cycle_invoice_id' => $invoice->id,
                ]);

                Log::info('Invoice created successfully', ['invoice_id' => $invoice->id ?? null, 'phase' => $phase]);

                try {
                    $this->invoiceService->sendInvoice($invoice->id);
                } catch (\Exception $e) {
                    Log::error('Failed to send invoice email in batch process', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to create invoice for subscription', [
                'subscription_id' => $subscription->id,
                'phase' => $subscription->current_phase,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $emails = explode(',', config('app.cron_notify_emails', ''));
            $emails = array_filter(array_map('trim', $emails));

            if (!empty($emails)) {
                $entity = $subscription->entity;
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name' => 'generateDueInvoices — Failure',
                    'error_message' => $e->getMessage(),
                    'timestamp' => now()->toDateTimeString(),
                    'stack_trace' => $e->getTraceAsString(),
                    'user_email' => $entity->email ?? 'N/A',
                    'user_id' => $entity->id ?? 'N/A',
                    'partner_name' => $entity->full_name ?? ($entity->partner?->full_name ?? 'N/A'),
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
     * Generate invoices for all active subscriptions whose expected_end_date is due.
     *
     * This is the single unified invoice generator for all subscription types:
     *  - enrollment   (Partner/Borrower down payment pending)
     *  - installment  (Partner/Borrower installment phase)
     *  - platform_fee (Partner/Borrower platform fee phase)
     *  - user_seat    (User/Employee monthly seat renewal)
     *
     * current_phase determines the invoice type; expected_end_date is the scheduling
     * trigger (see the class docblock on Subscription for how it differs from
     * subscription_end_date, which records what's actually been paid).
     * next_installment_date and next_platform_fee_date are deprecated and no longer used.
     */
    public function generateDueInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        Subscription::with(['entity', 'partnerPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('expected_end_date')
            ->whereDate('expected_end_date', '<=', $targetDate)
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $this->createInvoiceForSubscription($subscription);
                }
            });
    }

    /**
     * Get Subscription Statistics from cloned Query Builder.
     *
     * @param mixed $query
     * @return array
     */
    public function getStatsFromQuery($query): array
    {
        $cloned = clone $query;
        $cloned->setEagerLoads([]);
        $cloned->getQuery()->orders = null;
        $cloned->getQuery()->limit = null;
        $cloned->getQuery()->offset = null;

        $totalSubscriptions = (clone $cloned)->count();
        $totalActive = (clone $cloned)->where('subscriptions.status', Subscription::STATUS_ACTIVE)->count();
        $totalPending = (clone $cloned)->where('subscriptions.status', Subscription::STATUS_PENDING)->count();

        // Expiring Soon (next cycle due within 5 days) — a scheduling stat, keyed on
        // expected_end_date.
        $expiringSoon = (clone $cloned)->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscriptions.expected_end_date')
            ->whereBetween('subscriptions.expected_end_date', [now(), now()->addDays(5)])
            ->count();

        // Behind on payment — subscriptions whose subscription_end_date (paid
        // through) has already passed. Under the roll-forward billing model these
        // no longer freeze at Past Due, so this is the one place that still
        // surfaces real payment delinquency for an admin to see.
        $expiredCount = (clone $cloned)->whereNotNull('subscriptions.subscription_end_date')
            ->where('subscriptions.subscription_end_date', '<', now())
            ->count();

        // Revenue this month (from paid invoices linked to scoped partner)
        $revenueQuery = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year);

        $authUser = Auth::user();
        if ($authUser) {
            $partnerId = $authUser->partner_id;
            $partner = $authUser->partner ?? ($partnerId ? Partner::find($partnerId) : null);
            $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : false;
            if (!$isHost && $partnerId) {
                $revenueQuery->where('partner_id', $partnerId);
            }
        }

        $revenueThisMonth = $revenueQuery->sum('payment_amount');

        return [
            'total_subscriptions' => $totalSubscriptions,
            'active_subscriptions' => $totalActive,
            'pending_subscriptions' => $totalPending,
            'expiring_soon' => $expiringSoon,
            'expired_subscriptions' => $expiredCount,
            'revenue_this_month' => currencyFormat($revenueThisMonth),
        ];
    }

    /**
     * Get Subscription Statistics.
     */
    public function getStats(): array
    {
        $baseQuery = Subscription::query();
        $this->applyRbacScope($baseQuery);
        return $this->getStatsFromQuery($baseQuery);
    }

    /**
     * Get borrower fee for authenticated partner from their subscription,
     * or fallback to fixed enrollment plan from subscription_plans table.
     */
    public function getPartnerBorrowerFee()
    {
        $borrowerFee = SubscriptionPlan::where('enrollment_type_slug', SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG)
            ->where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
            ->value('total_amount') ?? 0;

        return ApiResponse::success(
            new PartnerBorrowerFeeResource((float) $borrowerFee),
            __('partner.borrower_fee_retrieved')
        );
    }
}
