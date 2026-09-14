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

        // next_installment_date / next_platform_fee_date used to live on the
        // subscription_phases side table. Both are now the same thing — the next
        // cycle the cron will bill — which is subscription_end_date, with
        // current_phase saying whether that cycle is an installment or a platform
        // fee. enrollment_completed_at is likewise a real column here now.
        if ($sortField === 'next_installment_date' || $sortField === 'next_platform_fee_date') {
            $query->orderBy('subscriptions.subscription_end_date', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'enrollment_completed_at') {
            $query->orderBy('subscriptions.enrollment_completed_at', $sortOrder);
            $skipSorting = true;
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

        // Calculate subscription_amount_per_month.
        // array_key_exists, not isset: an explicitly-passed null must be respected
        // rather than silently replaced with payable_amount.
        if (!array_key_exists('subscription_amount_per_month', $data)) {
            $totalSubAmount = $data['total_subscription_amount'] ?? 0;
            $downPayment = $data['down_payment'] ?? 0;
            $installmentCount = $data['installment_count'] ?? 0;
            $installmentType = $data['installment_type'] ?? 0;

            if ($installmentType == 1 && $installmentCount > 0) {
                $data['subscription_amount_per_month'] = ($totalSubAmount - $downPayment) / $installmentCount;
            } elseif (SubscriptionCreationService::isOneTimePayment($data['enrollment_type'] ?? null)) {
                // One Time Payment is settled in a single charge — no recurring
                // monthly amount exists, so this stays null.
                $data['subscription_amount_per_month'] = null;
            } else {
                // Other non-installment plans: use payable_amount, fall back to monthly_platform_fee.
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
            // Date-only columns: store the calendar date, never a timestamp.
            $updateData['subscription_start_date'] = today();
            $updateData['first_payment_date'] = today();
        }

        if ($isBorrower) {
            // A borrower's one-time enrollment is the whole debt: paying it finishes
            // them. Nothing recurs, so there is no cycle date to carry - and a date
            // here would be actively harmful, because isSubscriptionRestricted() in
            // both portals locks a profile once today is past subscription_end_date.
            // Setting it to today() locked every borrower out the day after they
            // paid in full.
            $updateData['status'] = Subscription::STATUS_ACTIVE;
            $updateData['current_phase'] = Subscription::PHASE_COMPLETED;
            $updateData['subscription_end_date'] = null;
        } else {
            // Partners roll into the recurring monthly platform fee, so they keep a
            // live cycle date.
            $updateData['status'] = Subscription::STATUS_ACTIVE;
            $updateData['current_phase'] = Subscription::PHASE_PLATFORM_FEE;
            $updateData['subscription_end_date'] = $this->nextCycleFrom($subscription);
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
        $nextCycle = $this->nextCycleFrom($subscription);
        $plan = $subscription->partnerPlan;

        // Set start date on first payment. subscription_start_date is only ever
        // written by this branch, so its absence alone reliably means "this is the
        // very first payment" — whether that payment was a down payment (current_phase
        // starts at PHASE_ENROLLMENT) or the first installment on a no-down-payment
        // plan (current_phase starts at PHASE_INSTALLMENT). Either way it migrates
        // the subscription into PHASE_INSTALLMENT below.
        if (!$subscription->subscription_start_date) {
            $subscription->update([
                'subscription_start_date' => today(),
                'first_payment_date' => today(),
                'installments_paid' => $invoice->type == Invoice::INVOICE_TYPE_DOWN_PAYMENT ? 0 : 1,
                'status' => Subscription::STATUS_ACTIVE,
                'current_phase' => Subscription::PHASE_INSTALLMENT,
                'subscription_end_date' => $nextCycle,
                'current_cycle_invoice_id' => null,
            ]);

        } elseif ($subscription->current_phase == Subscription::PHASE_INSTALLMENT) {

            $isLastInstallment = $subscription->installment_count == ($subscription->installments_paid + 1);
            $isBorrower = $subscription->entity_type === Borrower::class;

            $updateData = [
                'installments_paid' => $subscription->installments_paid + 1,
                'enrollment_completed_at' => $isLastInstallment ? now() : null,
                'subscription_end_date' => $nextCycle,
                'status' => Subscription::STATUS_ACTIVE,
                'current_cycle_invoice_id' => null,
            ];

            if ($isLastInstallment && $isBorrower) {
                // The final installment settles a borrower's whole debt. Nothing
                // recurs, so the cycle date is cleared for the same reason as the
                // one-time branch: a date left here locks them out of their profile
                // once it passes.
                $updateData['current_phase'] = Subscription::PHASE_COMPLETED;
                $updateData['subscription_end_date'] = null;
            } else {
                $updateData['current_phase'] = $isLastInstallment
                    ? Subscription::PHASE_PLATFORM_FEE
                    : Subscription::PHASE_INSTALLMENT;
            }

            $subscription->update($updateData);
        } else {
            // A borrower who already finished owes nothing. A later payment (a stray
            // fee invoice, say) must not revive their schedule - that would re-arm
            // the cron for someone with nothing left to bill, and lock them out once
            // the date passed.
            if ($subscription->entity_type === Borrower::class
                && $subscription->current_phase === Subscription::PHASE_COMPLETED) {
                $subscription->update([
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_cycle_invoice_id' => null,
                ]);

                return;
            }

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'subscription_end_date' => $nextCycle,
                'current_cycle_invoice_id' => null,
            ]);
        }

        return;
    }

    /**
     * Where a payment carries the subscription to.
     *
     *   paid BEFORE subscription_end_date -> subscription_end_date + 1 month
     *   paid on or after it (late)        -> today + 1 month
     *
     * Anchoring an early payment on the existing end date is what keeps the billing
     * day stable. An invoice is raised generate_before_days ahead of the due date,
     * so a customer who pays the moment it appears would otherwise walk their
     * billing day backwards by that many days every single cycle.
     *
     * Anchoring a late payment on today is what keeps them out of the lockout:
     * isSubscriptionRestricted() in both portals locks a profile once today is past
     * subscription_end_date, so carrying a date behind today would lock a customer
     * for the act of paying.
     *
     * This is the only cycle date now. expected_end_date is gone - with one open
     * invoice at a time a cycle is never rolled forward without payment, so the
     * second date it existed to carry has nothing left to record.
     */
    private function nextCycleFrom(Subscription $subscription): Carbon
    {
        $end = $subscription->subscription_end_date;

        $anchor = $end && Carbon::parse($end)->startOfDay()->gt(today())
            ? Carbon::parse($end)->startOfDay()
            : today();

        return $anchor->copy()->addMonth();
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

            // current_cycle_invoice_id -> null: matches handleOneTimePayment() /
            // handleInstallmentPayment() - this invoice is now paid, so it must stop
            // being "the open invoice for this cycle", otherwise createInvoiceIfDue()
            // keeps finding a (paid) invoice here and treats the cycle as already
            // billed instead of generating the next one.
            $subscription->update([
                'subscription_start_date' => today(),
                'first_payment_date' => today(),
                'subscription_end_date' => $newEnd,
                'status' => Subscription::STATUS_ACTIVE,
                'current_phase' => Subscription::PHASE_USER_SEAT,
                'current_cycle_invoice_id' => null,
            ]);
        } else {
            // Renewal: extend from where the seat is already paid through, so buying
            // a seat early never costs the days already paid for.
            $currentEnd = Carbon::parse($subscription->subscription_end_date);
            $newEnd = $currentEnd->addMonths($monthsPaid);

            $subscription->update([
                'subscription_end_date' => $newEnd,
                'status' => Subscription::STATUS_ACTIVE,
                'current_cycle_invoice_id' => null,
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
        $dueDate = $subscription->subscription_end_date;

        // No cycle date means nothing is scheduled: a borrower who has paid in
        // full, or a subscription whose first invoice is raised at enrollment
        // rather than by this cron.
        if (!$dueDate) {
            return false;
        }

        // One open invoice at a time. Until the customer pays what they already
        // owe, no further invoice is raised for them - nothing is expired, nothing
        // is rolled forward, and a debt can never stack into several invoices.
        // This replaces the old grace-window / missed-cycle rollover entirely.
        //
        // Only the subscription's OWN cycle invoices count. A non-host partner's
        // borrower fee is attached to the borrower's subscription for traceability
        // but is the partner's debt, so an unpaid one must not stop the borrower
        // being billed for their own installments.
        $hasOpenInvoice = Invoice::where('subscription_id', $subscription->id)
            ->where('status', Invoice::STATUS_UNPAID)
            ->where(function ($q) {
                $q->whereIn('type', Invoice::CYCLE_INVOICE_TYPES)
                    ->orWhereNull('type');
            })
            ->exists();

        if ($hasOpenInvoice) {
            return false;
        }

        $parsedDueDate = Carbon::parse($dueDate)->startOfDay();
        $generateBeforeDays = (int) config('subscription.generate_before_days', 5);
        $earliestGenerateDate = $parsedDueDate->copy()->subDays($generateBeforeDays)->startOfDay();

        // Too early to raise this cycle yet.
        if (Carbon::today()->lt($earliestGenerateDate)) {
            return false;
        }

        // No further guard is needed, and an extra one did harm. There used to be a
        // "was an invoice already raised for this subscription recently" check here,
        // which matched invoices that had since been PAID and so refused to bill a
        // subscription on any day it had already seen one - including the ordinary
        // case of enrolling, paying, and reaching the next cycle.
        //
        // The two checks above are already airtight against duplicates. Run the cron
        // twice in a day and the second run finds the first run's invoice open, so it
        // raises nothing. Pay that invoice in between and subscription_end_date has
        // moved a month out, so the cycle is no longer due. There is no third path.
        return true;
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
    /**
     * Why this subscription cannot be billed right now, or null if it can.
     *
     * A borrower is also blocked when their partner is not Active — the borrower
     * reaches the platform through that partner, so if the partner is switched off
     * the borrower is not receiving the service either.
     *
     * @param  Subscription $subscription
     * @param  mixed        $entity
     * @return string|null  Human-readable reason, used for the pause log.
     */
    protected function billingBlockReason(Subscription $subscription, $entity): ?string
    {
        if ($subscription->entity_type === Borrower::class) {
            if ($entity->status !== Borrower::STATUS_ACTIVE) {
                return 'Borrower status is ' . $entity->status;
            }

            $entity->loadMissing('partner');
            $partner = $entity->partner;

            if (!$partner) {
                return 'Borrower has no partner';
            }

            if ($partner->status !== Partner::STATUS_ACTIVE) {
                return "Borrower's partner status is " . $partner->status;
            }

            return null;
        }

        if ($subscription->entity_type === Partner::class) {
            return $entity->status !== Partner::STATUS_ACTIVE
                ? 'Partner status is ' . $entity->status
                : null;
        }

        if ($subscription->entity_type === User::class) {
            return $entity->status !== User::STATUS['active']
                ? 'User status is ' . $entity->status
                : null;
        }

        if ($subscription->entity_type === Employee::class) {
            $userRecord = User::where('entity_type', Employee::class)
                ->where('entity_id', $subscription->entity_id)
                ->first();

            if (!$userRecord) {
                return 'Employee has no user account';
            }

            return $userRecord->status !== User::STATUS['active']
                ? 'Employee user status is ' . $userRecord->status
                : null;
        }

        return null;
    }

    protected function createInvoiceForSubscription(Subscription $subscription): void
    {
        try {
            DB::transaction(function () use ($subscription) {

                $entity = $subscription->entity;

                if (!$entity || $subscription->status !== Subscription::STATUS_ACTIVE) {
                    return;
                }

                // Status guard per entity type.
                //
                // A subscriber who is not Active is not receiving the service, so
                // billing PAUSES rather than silently skipping. The distinction
                // matters on the way back: billing simply resumes with the one
                // invoice raised on the next run.
                $blockReason = $this->billingBlockReason($subscription, $entity);

                if ($blockReason) {
                    Log::info('Skipped billing: subscriber is not Active', [
                        'subscription_id' => $subscription->id,
                        'entity' => class_basename($subscription->entity_type) . '#' . $subscription->entity_id,
                        'reason' => $blockReason,
                    ]);

                    return;
                }

                // Safety net: a Borrower should never be in the recurring platform-fee
                // cycle — self-heal any stale/corrupted state instead of billing $0.
                // Status stays Active; current_phase is what records "nothing left to bill."
                if ($subscription->entity_type === Borrower::class && $subscription->current_phase === Subscription::PHASE_PLATFORM_FEE) {
                    // Clearing subscription_end_date stops the scheduling, and is
                    // what every other finished-borrower path writes.
                    $subscription->update([
                        'current_phase' => Subscription::PHASE_COMPLETED,
                        'subscription_end_date' => null,
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

                // Nothing is expired on the way in. Reaching this point means
                // createInvoiceIfDue() found no open cycle invoice, so there is
                // nothing to supersede - and superseding is exactly what the current
                // rule forbids: one invoice stands until the customer pays it.
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
     * Generate invoices for all active subscriptions whose cycle is due.
     *
     * This is the single unified invoice generator for all subscription types:
     *  - enrollment   (Partner/Borrower down payment pending)
     *  - installment  (Partner/Borrower installment phase)
     *  - platform_fee (Partner/Borrower platform fee phase)
     *  - user_seat    (User/Employee monthly seat renewal)
     *
     * current_phase determines the invoice type; subscription_end_date is the scheduling
     * trigger (see the class docblock on Subscription for how it differs from
     * subscription_end_date, which records what's actually been paid).
     * next_installment_date and next_platform_fee_date are deprecated and no longer used.
     */
    public function generateDueInvoices(): void
    {
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        // Keyed on subscription_end_date, the only cycle date left. Rows with an
        // invoice still open are skipped here as well as inside createInvoiceIfDue()
        // - doing it in the query keeps the chunk small when many customers are
        // behind.
        Subscription::with(['entity', 'partnerPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscription_end_date')
            ->whereDate('subscription_end_date', '<=', $targetDate)
            ->whereDoesntHave('invoices', function ($q) {
                $q->where('status', Invoice::STATUS_UNPAID)
                    ->where(function ($inner) {
                        $inner->whereIn('type', Invoice::CYCLE_INVOICE_TYPES)
                            ->orWhereNull('type');
                    });
            })
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
        // subscription_end_date, the only cycle date left.
        $expiringSoon = (clone $cloned)->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscriptions.subscription_end_date')
            ->whereBetween('subscriptions.subscription_end_date', [today()->toDateString(), today()->addDays(5)->toDateString()])
            ->count();

        // Behind on payment — subscriptions whose subscription_end_date (paid
        // through) has already passed. Under the roll-forward billing model these
        // no longer freeze at Past Due, so this is the one place that still
        // surfaces real payment delinquency for an admin to see.
        $expiredCount = (clone $cloned)->whereNotNull('subscriptions.subscription_end_date')
            ->whereDate('subscriptions.subscription_end_date', '<', today()->toDateString())
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
