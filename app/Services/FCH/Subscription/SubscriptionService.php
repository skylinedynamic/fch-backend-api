<?php

namespace App\Services\FCH\Subscription;

use App\Services\FCH\Common\Notification\Notifier;
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
use App\Mail\CronJobFailureMail;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Models\Subscription\Subscription;
use App\Services\Global\Datatable\QueryFilter;
use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Subscription\SubscriptionListResource;
use App\Http\Resources\FCH\Subscription\PartnerBorrowerFeeResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

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
        } elseif ($sortField === 'subscriber_name') {
            // The Name column: computed by the resource, not a column, so
            // ORDER BY subscriber_name was an SQL error (BUG-011).
            $query->orderBySubscriberName($sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'entity_type') {
            // Qualified: a partner/borrower join above must not make it ambiguous.
            $query->orderBy('subscriptions.entity_type', $sortOrder);
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

        // The borrower fee is the partner's debt to the host. It is linked to the
        // borrower's subscription only for traceability, so paying it must not move
        // that subscription or activate the borrower: they still owe their own
        // enrollment.
        if ($invoice->type === Invoice::INVOICE_TYPE_BORROWER_FEE) {
            $this->handleBorrowerFeePayment($invoice);
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
     * Record that the partner has paid the host the fee for this borrower.
     */
    private function handleBorrowerFeePayment(Invoice $invoice): void
    {
        if (!$invoice->borrower_id) {
            Log::warning('Borrower fee invoice has no borrower', ['invoice_id' => $invoice->id]);
            return;
        }

        Borrower::whereKey($invoice->borrower_id)->update(['partner_payment_for_me' => true]);
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
            // A calendar date. The column is DATE and would truncate a time
            // anyway; saying so here keeps the intent visible.
            'enrollment_completed_at' => $subscription->enrollment_completed_at ?: now()->startOfDay(),
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
            // live cycle date. The one-time payment covers the first
            // ONE_TIME_PAYMENT_COVERAGE_MONTHS from the day it was paid - always
            // from that day, never stacked on an end date the row already had.
            // Falls back to today for an invoice paid without one recorded.
            //
            // The monthly platform-fee payments that follow are routed here too
            // (dispatch is by enrollment_type), and each of those buys one month.
            $isPlatformFee = $invoice->type === Invoice::INVOICE_TYPE_PLATFORM_FEE
                || $invoice->is_platform_fee;

            $paidOn = $invoice->payment_date?->copy()->startOfDay() ?? today();

            $updateData['status'] = Subscription::STATUS_ACTIVE;
            $updateData['current_phase'] = Subscription::PHASE_PLATFORM_FEE;
            $updateData['subscription_end_date'] = $isPlatformFee
                ? $this->nextCycleFrom($subscription)
                : $paidOn->addMonths(Subscription::ONE_TIME_PAYMENT_COVERAGE_MONTHS);
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
            if (
                $subscription->entity_type === Borrower::class
                && $subscription->current_phase === Subscription::PHASE_COMPLETED
            ) {
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
    private function nextCycleFrom(Subscription $subscription, int $months = 1): Carbon
    {
        // subscription_end_date is cast to a date, so it arrives as Carbon already.
        $end = $subscription->subscription_end_date?->copy()->startOfDay();

        $anchor = $end && $end->gt(today()) ? $end : today();

        return $anchor->copy()->addMonths($months);
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
                    // Inline, not queued: it carries the new password.
                    app(Notifier::class)->send('user.seat_activated', $user, mail: (new PaymentSuccessUserMail([
                        'amount' => $invoice->payment_amount,
                        'currency' => $invoice->currency,
                        'transaction_id' => 'SUB_' . $subscription->id,
                        'description' => 'User seat activation',
                        'date' => now(),
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'plainPassword' => $plainPassword,
                        'user' => $user,
                    ], $user->partner_id))->to($user->email));
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
            // Renewal: paid early, extend from where the seat is already paid
            // through, so it never costs the days already paid for; paid late, run
            // from today, so paying does not leave the user locked out behind a
            // date still in the past. The same rule as every other cycle.
            $newEnd = $this->nextCycleFrom($subscription, (int) $monthsPaid);

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

        // Nothing scheduled: a borrower who has paid in full, or a subscription
        // whose first invoice is raised at enrollment rather than by this cron.
        if (!$dueDate) {
            return false;
        }

        // One open invoice at a time. Until the customer pays what they already owe,
        // no further invoice is raised - nothing is expired, nothing is rolled
        // forward, and a debt can never stack into several invoices. Only the
        // subscription's own cycle counts, so a borrower fee the PARTNER owes cannot
        // hold the borrower's billing hostage.
        if (Invoice::where('subscription_id', $subscription->id)->openCycle()->exists()) {
            return false;
        }

        // Raised generate_before_days ahead of the due date, so the invoice is
        // waiting when the cycle lands.
        $generateFrom = $dueDate->copy()
            ->startOfDay()
            ->subDays((int) config('subscription.generate_before_days', 5));

        // Those two checks are together airtight against duplicates, so there is no
        // third guard: run the cron twice in a day and the second run finds the
        // first run's invoice open; pay it in between and the cycle date has moved a
        // month out. A "was one raised recently" check used to sit here and did harm,
        // matching invoices that had since been PAID.
        return today()->gte($generateFrom);
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
                // (installments_paid), not raw invoice row count. Invoice rows are not
                // payments: older subscriptions still carry Expired rows from the
                // retired roll-forward billing, and counting them would stop billing
                // before the plan is actually paid off.
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

                // Amount by phase. $details explains an amount that differs
                // from the usual one (the adjusted last installment).
                $details = null;
                if ($phase === Subscription::PHASE_PLATFORM_FEE) {
                    $amount = $subscription->monthly_platform_fee;
                } elseif ($phase === Subscription::PHASE_USER_SEAT) {
                    // User/Employee seat renewal — billed at the per-seat rate, not
                    // monthly_platform_fee (which only applies to Partner subscriptions).
                    $amount = $subscription->per_seat_amount;
                } else {
                    // enrollment or installment
                    if ($subscription->installment_type == 1 && $subscription->installment_count > 0) {
                        // Has the first cycle been billed yet? Counted over the
                        // subscription's OWN cycle invoices: a borrower fee is the
                        // partner's separate charge, and counting it made the row
                        // look already-billed so the down payment was never raised.
                        $isFirstCycle = !Invoice::where('subscription_id', $subscription->id)
                            ->whereIn('type', [
                                Invoice::INVOICE_TYPE_ENROLLMENT,
                                Invoice::INVOICE_TYPE_DOWN_PAYMENT,
                                Invoice::INVOICE_TYPE_INSTALLMENT,
                            ])
                            ->exists();

                        $hasDownPayment = $subscription->down_payment && $subscription->down_payment > 0;

                        // The monthly figure as EnrollmentService calculated it at
                        // signup. Read, never recomputed: an installment plan always
                        // carries subscription_amount_per_month, so a missing one is
                        // a broken row, and billing something derived would paper
                        // over it with a number nobody agreed to.
                        //
                        // This used to be (total_subscription_amount - down_payment)
                        // / installment_count, which subtracted the down payment a
                        // SECOND time: total_subscription_amount is already the
                        // remaining balance after it. A 7696 plan with 1000 down over
                        // 12 months was billed 474.67 a month instead of 558.00, so
                        // the customer was undercharged every cycle and the plan
                        // could never settle.
                        //
                        // The last installment takes up the rounding of the monthly
                        // figure, so the installments total the plan exactly
                        // (BUG-049) - see Subscription::finalInstallmentAmount().
                        $isDownPaymentCycle = $isFirstCycle && $hasDownPayment;
                        $amount = $isDownPaymentCycle
                            ? $subscription->down_payment
                            : $subscription->nextInstallmentAmount();

                        if (!$isDownPaymentCycle && $subscription->isNextInstallmentFinal()) {
                            $details = $this->finalInstallmentDetails($subscription);
                        }
                    } else {
                        $amount = $subscription->payable_amount;
                    }
                }

                // Entity display info
                $customerName = $entity->full_name ?? personName($entity->first_name ?? null, $entity->middle_name ?? null, $entity->last_name ?? null);
                $email = $entity->email;

                // For user seat: bill the partner
                if ($isUserSeatPhase) {
                    $partner = Partner::find($entity->partner_id);
                    // The person billed, never the company (see InvoiceCustomerNameTest).
                    $customerName = $partner ? $partner->full_name : $customerName;
                    $email = $partner ? $partner->email : $email;
                }

                // Never generate a zero/invalid-amount invoice — a $0 charge attempt on
                // Stripe fails and spams the admin failure-notification mailbox every cycle.
                if ($amount === null || (float) $amount <= 0) {
                    // Name the column the phase should have billed from, so a broken
                    // row is identifiable from the log line alone rather than being
                    // reported every cycle as a nameless "zero amount".
                    $source = match ($phase) {
                        Subscription::PHASE_PLATFORM_FEE => 'monthly_platform_fee',
                        Subscription::PHASE_USER_SEAT => 'per_seat_amount',
                        Subscription::PHASE_INSTALLMENT => 'subscription_amount_per_month',
                        default => 'payable_amount',
                    };

                    Log::warning('Skipped invoice generation: zero/invalid amount', [
                        'subscription_id' => $subscription->id,
                        'phase' => $phase,
                        'amount' => $amount,
                        'expected_from' => $source,
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
                    'details' => $details,
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
                app(Notifier::class)->send('system.cron_failed', mail: (new CronJobFailureMail([
                    'job_name' => 'generateDueInvoices — Failure',
                    'error_message' => $e->getMessage(),
                    'timestamp' => now()->toDateTimeString(),
                    'stack_trace' => $e->getTraceAsString(),
                    'user_email' => $entity->email ?? 'N/A',
                    'user_id' => $entity->id ?? 'N/A',
                    'partner_name' => $entity->full_name ?? ($entity->partner?->full_name ?? 'N/A'),
                ]))->to($emails));
            }
        }
    }

    /**
     * The invoice details of a plan's last installment when it differs from the
     * monthly one (BUG-049): its line item names it and says why, and the
     * figures are kept for the email and the bell (Invoice::finalInstallmentNote()).
     * Null when the last installment is the monthly amount - nothing to explain.
     */
    private function finalInstallmentDetails(Subscription $subscription): ?array
    {
        $adjustment = $subscription->finalInstallmentAdjustment();

        if ($adjustment === null) {
            return null;
        }

        $count = (int) $subscription->installment_count;
        $final = [
            'adjustment' => $adjustment,
            'monthly' => (float) $subscription->subscription_amount_per_month,
            'total' => (float) $subscription->total_subscription_amount,
            'count' => $count,
        ];

        // Formatted by the same rule the email and the bell use.
        $note = (new Invoice(['details' => ['final_installment' => $final]]))->finalInstallmentNote();

        return [
            'items' => [[
                'description' => __('invoice.final_installment.description', ['number' => $note['number'], 'count' => $note['count']]),
                'detail' => __('invoice.final_installment.detail', [
                    'difference' => $note['difference'],
                    'monthly' => $note['monthly'],
                    'count' => $note['count'],
                    'total' => $note['total'],
                ]),
                'amount' => $subscription->finalInstallmentAmount(),
            ]],
            'final_installment' => $final,
        ];
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
        $targetDate = today()
            ->addDays((int) config('subscription.generate_before_days', 5))
            ->toDateString();

        // Keyed on subscription_end_date, the only cycle date left. Rows with an
        // invoice still open are excluded here as well as inside
        // createInvoiceIfDue(), which keeps each chunk small when many customers are
        // behind.
        //
        // A plain comparison, not whereDate(): the column is already a DATE, so
        // wrapping it in DATE() bought nothing and made its index unusable.
        // The borrower's partner is eager loaded because billingBlockReason() reads
        // it for every borrower — without it the cron ran a query per row.
        Subscription::query()
            ->with([
                'entity' => fn($morphTo) => $morphTo->morphWith([Borrower::class => ['partner']]),
                'partnerPlan',
            ])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscription_end_date')
            ->where('subscription_end_date', '<=', $targetDate)
            ->whereDoesntHave('invoices', fn($q) => $q->openCycle())
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
