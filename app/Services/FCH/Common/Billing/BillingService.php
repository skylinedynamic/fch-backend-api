<?php

namespace App\Services\FCH\Common\Billing;

use App\Models\Subscription\Subscription;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Http\Resources\FCH\Billing\BillingResource;
use App\Http\Resources\FCH\Subscription\SubscriptionDetailsResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Closure;
use Illuminate\Support\Collection;

class BillingService
{
    use PaginatesApi;

    /**
     * Fetch upcoming billings.
     *
     * @param array $filters
     * @return array
     */
    public function fetchUpcoming(array $filters)
    {
        $authUser = auth()->user();
        $partner = $authUser?->partner ?? ($authUser?->partner_id ? Partner::find($authUser->partner_id) : null);
        $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : false;
        $userPartnerId = $partner?->id ?? $authUser?->partner_id;

        $windowDays = (int) config('subscription.upcoming_window_days', 10);
        $targetDate = now()->addDays($windowDays)->toDateString();

        // Who pays the viewer, and for what:
        //
        // - A non-host partner is paid only by its own clients (their enrollment
        //   and installments). Its own plan and staff seats are money it owes the
        //   host, and so is the borrower fee it pays to activate a client - even
        //   though that fee invoice sits on the client's subscription.
        // - The host is paid by its own clients, and by every other partner: their
        //   plan (enrollment, installment, platform fee), their staff seats, and
        //   the borrower fee for each client they activate. Neither the host's own
        //   plan nor its own staff are billed - the host does not bill itself.
        //
        // Every row is a subscription. A borrower fee has none of its own, so on
        // the host's list a partner's client appears only while the partner still
        // owes its borrower fee, and that row is shown as the partner's
        // (BillingResource::ROW_BORROWER_FEE).
        $feeRowsShown = $isHost && $userPartnerId;
        [$feeSql, $feeBindings] = $feeRowsShown ? $this->borrowerFeeRowSql((int) $userPartnerId) : ['(1 = 0)', []];

        // Of a row's open invoices, which are owed to the viewer: everything on
        // the host's own rows, only the client's own bills on a partner's.
        $receivable = $isHost
            ? fn ($invoices) => $invoices
            : fn ($invoices) => $invoices->where('invoices.is_partner_paying', false);

        $query = Subscription::query()
            ->select('subscriptions.*')
            ->selectRaw(
                "CASE WHEN {$feeSql} THEN ? ELSE ? END AS billing_row",
                [...$feeBindings, BillingResource::ROW_BORROWER_FEE, BillingResource::ROW_SUBSCRIPTION]
            )
            ->with([
                // status is selected so is_billing_paused can read the subscriber's
                // LIVE state rather than waiting for the cron to stamp the flag.
                'partner:id,full_name,company_name,email,status',
                'borrower:id,first_name,last_name,email,partner_id,status',
                'borrower.partner:id,full_name,company_name,email,status',
                // Seat subscriptions (User / Employee) — needed so BillingResource can
                // show who the seat belongs to instead of a blank name.
                'user:id,name,email,partner_id,status',
                'employee:id,first_name,last_name,email,partner_id',
                // Drives outstanding_amount on each row and the summary totals —
                // eager loaded so the resource never lazy-loads per row.
                'openInvoices:id,invoice_id,subscription_id,payment_amount,status,type,is_partner_paying,created_at',
            ])
            ->where(function ($rows) use ($isHost, $userPartnerId, $receivable, $targetDate, $feeRowsShown) {
                $rows->where(function ($q) use ($isHost, $userPartnerId, $receivable, $targetDate) {
                    $this->whereBilledToViewer($q, $isHost, $userPartnerId);
                    $this->whereCollecting($q, $receivable);
                    $this->whereDueWithinWindow($q, $receivable, $targetDate);
                });

                if ($feeRowsShown) {
                    $rows->orWhere(function ($q) use ($userPartnerId) {
                        $this->whereBorrowerFeeRow($q, (int) $userPartnerId)
                            ->whereHas('openInvoices', fn ($invoices) => $this->borrowerFeeInvoices($invoices));
                    });
                }
            });

        // Entity type filter. The API speaks the same vocabulary as the frontend
        // (User::PARTNER / User::BORROWER, matching ENTITY_TYPE_* in appConfig.ts)
        // and maps it to the fully-qualified model class stored in entity_type.
        // A borrower fee row is the partner's, whatever subscription carries it.
        $entityType = $filters['entity_type'] ?? null;

        if ($entityType === User::PARTNER) {
            $query->where(fn ($q) => $q->where('subscriptions.entity_type', Partner::class)
                ->orWhereRaw($feeSql, $feeBindings));
        } elseif ($entityType === User::BORROWER) {
            $query->where('subscriptions.entity_type', Borrower::class)
                ->whereRaw("NOT {$feeSql}", $feeBindings);
        } elseif ($entityType === User::USER) {
            // Host-only filter option: seats billed to an admin/staff User.
            $query->where('subscriptions.entity_type', User::class);
        } elseif ($entityType === User::EMPLOYEE) {
            // Host-only filter option: seats billed to a partner's Employee.
            $query->where('subscriptions.entity_type', Employee::class);
        }

        // View filter: lets staff jump straight to the money that needs a decision
        // rather than scrolling a mixed list.
        //   paused    - not collectable; subscriber is not Active
        //   overdue   - scheduled cycle already past and still not collected
        //   scheduled - genuinely upcoming
        // A borrower fee is due as soon as it is raised: overdue while unpaid,
        // never scheduled, and paused only while the partner is not Active.
        $view = $filters['view'] ?? null;

        if ($view === 'paused') {
            $query->where(fn ($q) => $q
                ->where(fn ($row) => $this->wherePaused($row->whereRaw("NOT {$feeSql}", $feeBindings)))
                ->orWhere(fn ($fee) => $this->whereFeePartnerActive($fee->whereRaw($feeSql, $feeBindings), false)));
        } elseif ($view === 'overdue') {
            $query->where(fn ($q) => $q
                ->where(fn ($row) => $row->whereRaw("NOT {$feeSql}", $feeBindings)
                    ->whereNot(fn ($paused) => $this->wherePaused($paused))
                    ->whereNotNull('subscriptions.subscription_end_date')
                    ->whereDate('subscriptions.subscription_end_date', '<', now()->toDateString()))
                ->orWhere(fn ($fee) => $this->whereFeePartnerActive($fee->whereRaw($feeSql, $feeBindings), true)));
        } elseif ($view === 'scheduled') {
            $query->whereRaw("NOT {$feeSql}", $feeBindings)
                ->whereNot(fn ($q) => $this->wherePaused($q))
                ->whereNotNull('subscriptions.subscription_end_date')
                ->whereDate('subscriptions.subscription_end_date', '>=', now()->toDateString());
        }

        // Global Search
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        if (!empty($globalSearch)) {
            $query->search($globalSearch);
        }

        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']);

        // Custom Filters for Columns
        if (!empty($rawFilters['partner_name'])) {
            $val = $rawFilters['partner_name']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where(function ($q) use ($val) {
                    $q->whereHas('partner', function ($sub) use ($val) {
                        $sub->where('full_name', 'like', "%{$val}%")
                            ->orWhere('company_name', 'like', "%{$val}%");
                    })->orWhereHas('borrower', function ($sub) use ($val) {
                        $sub->whereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$val}%"]);
                    });
                });
            }
            unset($rawFilters['partner_name']);
        }

        if (!empty($rawFilters['upcoming_billing_type'])) {
            $val = $rawFilters['upcoming_billing_type']['constraints'][0]['value'] ?? null;
            if ($val) {
                $normalized = strtolower(str_replace(' ', '_', $val));
                if ($normalized === BillingResource::ROW_BORROWER_FEE) {
                    $query->whereRaw($feeSql, $feeBindings);
                } elseif (in_array($normalized, [Subscription::PHASE_PLATFORM_FEE, Subscription::PHASE_INSTALLMENT, Subscription::PHASE_USER_SEAT])) {
                    $query->where('subscriptions.current_phase', $normalized)->whereRaw("NOT {$feeSql}", $feeBindings);
                } else {
                    $query->where('subscriptions.current_phase', 'like', "%{$val}%")->whereRaw("NOT {$feeSql}", $feeBindings);
                }
            }
            unset($rawFilters['upcoming_billing_type']);
        }

        if (!empty($rawFilters['upcoming_billing_amount'])) {
            $val = $rawFilters['upcoming_billing_amount']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where(function ($q) use ($val) {
                    $q->where(function ($sub) use ($val) {
                        $sub->where('subscriptions.current_phase', Subscription::PHASE_INSTALLMENT)
                            ->where('subscriptions.subscription_amount_per_month', 'like', "%{$val}%");
                    })->orWhere(function ($sub) use ($val) {
                        $sub->where('subscriptions.current_phase', Subscription::PHASE_PLATFORM_FEE)
                            ->where('subscriptions.monthly_platform_fee', 'like', "%{$val}%");
                    })->orWhere(function ($sub) use ($val) {
                        $sub->where('subscriptions.current_phase', Subscription::PHASE_USER_SEAT)
                            ->where('subscriptions.per_seat_amount', 'like', "%{$val}%");
                    });
                });
            }
            unset($rawFilters['upcoming_billing_amount']);
        }

        if (!empty($rawFilters['upcoming_billing_date'])) {
            $val = $rawFilters['upcoming_billing_date']['constraints'][0]['value'] ?? null;
            if ($val) {
                // Plain LIKE on the column rather than whereDate(): wrapping the
                // column in DATE() makes the index unusable, and
                // subscription_end_date is already a date column so the cast bought
                // nothing.
                $query->where('subscriptions.subscription_end_date', 'like', "%{$val}%");
            }
            unset($rawFilters['upcoming_billing_date']);
        }

        if (!empty($rawFilters['enrollment_type'])) {
            $val = $rawFilters['enrollment_type']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where('subscriptions.enrollment_type', 'like', "%{$val}%");
            }
            unset($rawFilters['enrollment_type']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'upcoming_billing_date';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $skipSorting = false;

        switch ($sortField) {
            // All four name the same column now that there is one cycle date.
            // paid_through_date is no longer sent to the client; the case stays so
            // a saved sort preference from before the change still works.
            case 'upcoming_billing_date':
            case 'next_billing_date':
            case 'paid_through_date':
            case 'subscription_end_date':
                // Undated rows (awaiting a first payment) go after every dated
                // one either way - MySQL would put NULLs first on ascending (BUG-012).
                // A borrower fee row goes by the day its fee was raised.
                [$feeDate, $feeDateBindings] = $this->borrowerFeeSql('MIN(DATE(invoices.created_at))');
                $dateSql = "CASE WHEN {$feeSql} THEN {$feeDate} ELSE subscriptions.subscription_end_date END";
                $dateBindings = [...$feeBindings, ...$feeDateBindings];
                $query->orderByRaw("({$dateSql}) IS NULL", $dateBindings)
                    ->orderByRaw("{$dateSql} {$sortOrder}", $dateBindings);
                $skipSorting = true;
                break;

            // Sorts on the amount the row shows (BUG-012) - see billingAmountSql().
            case 'upcoming_billing_amount':
                // A borrower fee row shows the fee it is for.
                [$feeSum, $feeSumBindings] = $this->borrowerFeeSql('COALESCE(SUM(invoices.payment_amount), 0)');
                $query->orderByRaw("CASE WHEN {$feeSql} THEN {$feeSum} ELSE " . $this->billingAmountSql() . " END {$sortOrder}", [
                    ...$feeBindings,
                    ...$feeSumBindings,
                    Subscription::PHASE_INSTALLMENT,
                    Subscription::PHASE_PLATFORM_FEE,
                    Subscription::PHASE_USER_SEAT,
                    Subscription::PHASE_COMPLETED,
                ]);
                $skipSorting = true;
                break;

            // The open invoices' total the row shows as outstanding_amount -
            // the same invoices receivableFor() counts, so paid and deleted ones
            // are out, and so is a borrower fee on a partner's own list.
            case 'outstanding_amount':
                [$feeSum, $feeSumBindings] = $this->borrowerFeeSql('COALESCE(SUM(invoices.payment_amount), 0)');
                $owedSql = '(SELECT COALESCE(SUM(invoices.payment_amount), 0) FROM invoices'
                    . ' WHERE invoices.subscription_id = subscriptions.id AND invoices.deleted_at IS NULL'
                    . ' AND invoices.status IN (' . implode(', ', array_fill(0, count(Invoice::OPEN_STATUSES), '?')) . ')'
                    . ($isHost ? '' : ' AND invoices.is_partner_paying = 0') . ')';
                $query->orderByRaw(
                    "CASE WHEN {$feeSql} THEN {$feeSum} ELSE {$owedSql} END {$sortOrder}",
                    [...$feeBindings, ...$feeSumBindings, ...Invoice::OPEN_STATUSES]
                );
                $skipSorting = true;
                break;

            case 'upcoming_billing_type':
                $query->orderByRaw(
                    "CASE WHEN {$feeSql} THEN ? ELSE subscriptions.current_phase END {$sortOrder}",
                    [...$feeBindings, BillingResource::ROW_BORROWER_FEE]
                );
                $skipSorting = true;
                break;

            // The Name column shows whoever is billed - partner, client or seat -
            // so it sorts on that name, not the partner's alone: on a white-label
            // partner's list every row is a client and sorted on NULL (BUG-009).
            // No join either: an unselected join let the partner's id and status
            // overwrite the subscription's own on every row.
            case 'partner_name':
            case 'borrower_name':
            case 'subscriber_name':
                // A borrower fee row shows (and sorts on) the partner's name.
                $query->orderBySubscriberName($sortOrder, $feeRowsShown ? [
                    $feeSql,
                    $feeBindings,
                    '(SELECT partners.full_name FROM partners JOIN borrowers ON borrowers.partner_id = partners.id WHERE borrowers.id = subscriptions.entity_id)',
                ] : null);
                $skipSorting = true;
                break;

            case 'enrollment_type':
                $query->orderBy('subscriptions.enrollment_type', $sortOrder);
                $skipSorting = true;
                break;

            case 'status':
                $query->orderBy('subscriptions.status', $sortOrder);
                $skipSorting = true;
                break;

            default:
                $query->orderBy('subscriptions.id', $sortOrder);
                $skipSorting = true;
                break;
        }

        // Run remaining filters through QueryFilter helper
        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        // Totals across the WHOLE filtered set, not just the current page — a
        // per-page total would be meaningless for deciding what to chase.
        $summary = $this->summarise(clone $query, $isHost);

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);
        $paginator->getCollection()->each(
            fn (Subscription $row) => $row->setRelation('receivableInvoices', $this->receivableFor($row, $isHost))
        );

        return $this->respondWithPaginationResource(
            $paginator,
            BillingResource::class,
            __('billing.upcoming_retrieved'),
            200,
            [
                // Returned so the UI can state the real window instead of hardcoding
                // a number that silently drifts from the config.
                'window_days' => $windowDays,
                'summary' => $summary,
            ]
        );
    }

    /**
     * The upcoming_billing_amount BillingResource shows, as SQL, so sorting by
     * Amount follows the figures on screen. Keep the two in step. The columns
     * are decimal:2 strings, so the resource's `?:` only falls through on NULL -
     * a stored 0.00 is shown as $0 - which COALESCE matches.
     * Bindings: installment, platform fee, user seat and completed phases.
     */
    private function billingAmountSql(): string
    {
        // Installment: the last one takes up the monthly rounding, as
        // Subscription::nextInstallmentAmount() works it out (BUG-049).
        return "CASE
            WHEN subscriptions.current_phase = ? THEN CASE
                WHEN subscriptions.installment_type = 1
                    AND subscriptions.installment_count > 0
                    AND subscriptions.installments_paid = subscriptions.installment_count - 1
                    AND subscriptions.total_subscription_amount IS NOT NULL
                    AND subscriptions.subscription_amount_per_month IS NOT NULL
                    AND subscriptions.total_subscription_amount - subscriptions.subscription_amount_per_month * (subscriptions.installment_count - 1) > 0
                THEN subscriptions.total_subscription_amount - subscriptions.subscription_amount_per_month * (subscriptions.installment_count - 1)
                ELSE COALESCE(subscriptions.subscription_amount_per_month, 0)
            END
            WHEN subscriptions.current_phase = ? THEN COALESCE(subscriptions.monthly_platform_fee, 0)
            WHEN subscriptions.current_phase = ? THEN COALESCE(subscriptions.per_seat_amount, 0)
            WHEN subscriptions.current_phase = ? THEN 0
            WHEN subscriptions.down_payment > 0 AND subscriptions.installments_paid = 0 THEN subscriptions.down_payment
            ELSE COALESCE(subscriptions.subscription_amount_per_month, subscriptions.payable_amount, 0)
        END";
    }

    /**
     * Constrain to subscriptions whose billing is stopped.
     *
     * Delegates to the model scope so this screen and the auto-charge dispatcher
     * can never disagree about who is collectable.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     */
    protected function wherePaused($query)
    {
        return $query->billingBlocked();
    }

    /**
     * Totals for the whole filtered set.
     *
     * Answers the two questions this screen exists for: how much is scheduled to
     * come in, and how much is owed but cannot currently be collected because the
     * subscriber is not Active. The second number was previously invisible
     * everywhere in the system.
     */
    protected function summarise($query, bool $isHost): array
    {
        // The query already selects subscriptions.* and billing_row, which the
        // amounts, dates and the borrower fee split below all read.
        $rows = (clone $query)
            ->with([
                'openInvoices:id,invoice_id,subscription_id,payment_amount,status,type,is_partner_paying,created_at',
                // Needed by is_billing_paused, which is derived entirely from the
                // subscriber's live status.
                'partner:id,status',
                'borrower:id,partner_id,status',
                'borrower.partner:id,status',
            ])
            ->get();

        $scheduledTotal = 0.0;
        $outstandingTotal = 0.0;
        $pausedTotal = 0.0;
        $pausedCount = 0;
        $overdueCount = 0;

        foreach ($rows as $row) {
            // Already invoiced and still owed — counted for every row, paused or
            // not, because it is real money regardless of why it is uncollected.
            $outstanding = (float) $this->receivableFor($row, $isHost)->sum('payment_amount');
            $outstandingTotal += $outstanding;

            // A borrower fee is owed from the day it is raised: overdue unless the
            // partner is not Active, and never part of what is scheduled.
            if ($row->billing_row === BillingResource::ROW_BORROWER_FEE) {
                if (BillingResource::borrowerFeePausedReason($row) !== null) {
                    $pausedCount++;
                    $pausedTotal += $outstanding;
                } else {
                    $overdueCount++;
                }
                continue;
            }

            if ($row->is_billing_paused) {
                $pausedCount++;
                $pausedTotal += $outstanding;
                continue;
            }

            // Only a row with a scheduled cycle contributes to "scheduled". A
            // never-paid Pending subscriber has nothing scheduled — their money is
            // already counted as outstanding above, and counting it here too would
            // double it.
            if (!$row->subscription_end_date) {
                continue;
            }

            $scheduledTotal += match ($row->current_phase) {
                Subscription::PHASE_INSTALLMENT => (float) ($row->nextInstallmentAmount() ?: 0),
                Subscription::PHASE_PLATFORM_FEE => (float) ($row->monthly_platform_fee ?: 0),
                Subscription::PHASE_USER_SEAT => (float) ($row->per_seat_amount ?: 0),
                Subscription::PHASE_COMPLETED => 0.0,
                default => (float) ($row->payable_amount ?: 0),
            };

            // subscription_end_date is a DATE, so it reads back as midnight.
            // Compare against today's date, not now(), or a cycle due today counts
            // overdue.
            if ($row->subscription_end_date->lt(today())) {
                $overdueCount++;
            }
        }

        return [
            'scheduled_total' => round($scheduledTotal, 2),
            'outstanding_total' => round($outstandingTotal, 2),
            'paused_outstanding_total' => round($pausedTotal, 2),
            'paused_count' => $pausedCount,
            'overdue_count' => $overdueCount,
            'total_count' => $rows->count(),
        ];
    }

    /**
     * The open invoices a row counts as owed to the viewer. A borrower fee row
     * holds only that fee; on a partner's own list the borrower fee it pays the
     * host is never its receivable.
     */
    private function receivableFor(Subscription $row, bool $isHost): Collection
    {
        $open = $row->relationLoaded('openInvoices') ? $row->openInvoices : collect();

        if ($row->billing_row === BillingResource::ROW_BORROWER_FEE) {
            return $open->filter(fn (Invoice $invoice) => $invoice->type === Invoice::INVOICE_TYPE_BORROWER_FEE
                && (bool) $invoice->is_partner_paying)->values();
        }

        return $isHost
            ? $open->values()
            : $open->reject(fn (Invoice $invoice) => (bool) $invoice->is_partner_paying)->values();
    }

    /** Regular rows: what the viewer bills (see fetchUpcoming()). */
    private function whereBilledToViewer($query, bool $isHost, $userPartnerId)
    {
        if ($isHost && $userPartnerId) {
            return $query->where(function ($q) use ($userPartnerId) {
                $q->where(function ($sub) use ($userPartnerId) {
                    // Every other partner's plan, never the host's own.
                    $sub->where('subscriptions.entity_type', Partner::class)
                        ->where('subscriptions.entity_id', '!=', $userPartnerId);
                })
                    ->orWhere(function ($sub) use ($userPartnerId) {
                        // The host's own direct clients only — a partner's clients
                        // pay that partner, not the host.
                        $sub->where('subscriptions.entity_type', Borrower::class)
                            ->whereExists(function ($inner) use ($userPartnerId) {
                                $inner->selectRaw(1)
                                    ->from('borrowers')
                                    ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                                    ->where('borrowers.partner_id', $userPartnerId);
                            });
                    })
                    // Every other partner's paid seats, never the host's own staff.
                    ->orWhere(fn ($sub) => $this->scopeSeatSubscriptions($sub, null, $userPartnerId));
            });
        }

        if ($userPartnerId) {
            // A partner's only receivable: its own clients.
            return $query->where('subscriptions.entity_type', Borrower::class)
                ->whereExists(function ($inner) use ($userPartnerId) {
                    $inner->selectRaw(1)
                        ->from('borrowers')
                        ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                        ->where('borrowers.partner_id', $userPartnerId);
                });
        }

        return $query;
    }

    /**
     * Active subscriptions, plus Pending ones that already owe the viewer money.
     * Pending means enrolled but never paid — their signup invoice is open, so
     * they are exactly the people who need chasing.
     */
    private function whereCollecting($query, Closure $receivable)
    {
        return $query->where(function ($q) use ($receivable) {
            $q->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                ->orWhere(function ($pending) use ($receivable) {
                    $pending->where('subscriptions.status', Subscription::STATUS_PENDING)
                        ->whereHas('openInvoices', $receivable);
                });
        });
    }

    /**
     * Which cycles are "upcoming".
     *
     * Keyed on subscription_end_date because that is the field
     * generateDueInvoices() reads — this screen exists to predict what the cron
     * will do, so the two must select on the same column. A NULL date means
     * nothing is scheduled, which also excludes finished borrowers
     * (PHASE_COMPLETED clears it) — they would otherwise show up here forever as
     * $0.00 rows, since status stays Active on completion.
     */
    private function whereDueWithinWindow($query, Closure $receivable, string $targetDate)
    {
        return $query->where(function ($q) use ($receivable, $targetDate) {
            $q->where(function ($sub) use ($targetDate) {
                $sub->whereNotNull('subscriptions.subscription_end_date')
                    ->whereDate('subscriptions.subscription_end_date', '<=', $targetDate);
            })->orWhere(function ($sub) {
                // Enrolled but never paid: no cycle is scheduled yet and their signup
                // invoice is already open. Kept on the list so staff can chase them.
                $sub->whereNull('subscriptions.subscription_end_date')
                    ->whereNull('subscriptions.enrollment_completed_at');
            })
                // Anything still owed to the viewer, whatever its dates say.
                ->orWhereHas('openInvoices', $receivable);
        });
    }

    /** A partner's client's subscription, on the host's list: it carries that partner's borrower fee. */
    private function whereBorrowerFeeRow($query, int $hostId)
    {
        return $query->where('subscriptions.entity_type', Borrower::class)
            ->whereExists(function ($inner) use ($hostId) {
                $inner->selectRaw(1)
                    ->from('borrowers')
                    ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                    ->where('borrowers.partner_id', '!=', $hostId);
            });
    }

    /** whereBorrowerFeeRow() as raw SQL, for the select and the sorts. */
    private function borrowerFeeRowSql(int $hostId): array
    {
        return [
            '(subscriptions.entity_type = ? AND EXISTS (SELECT 1 FROM borrowers'
                . ' WHERE borrowers.id = subscriptions.entity_id AND borrowers.partner_id <> ?))',
            [Borrower::class, $hostId],
        ];
    }

    /** The borrower fee a partner pays the host for a client. */
    private function borrowerFeeInvoices($invoices)
    {
        return $invoices->where('invoices.type', Invoice::INVOICE_TYPE_BORROWER_FEE)
            ->where('invoices.is_partner_paying', true);
    }

    /** $aggregate over the row's open borrower fee invoices, as a SQL subquery. */
    private function borrowerFeeSql(string $aggregate): array
    {
        return [
            "(SELECT {$aggregate} FROM invoices WHERE invoices.subscription_id = subscriptions.id"
                . ' AND invoices.deleted_at IS NULL AND invoices.type = ? AND invoices.is_partner_paying = 1'
                . ' AND invoices.status IN (' . implode(', ', array_fill(0, count(Invoice::OPEN_STATUSES), '?')) . '))',
            [Invoice::INVOICE_TYPE_BORROWER_FEE, ...Invoice::OPEN_STATUSES],
        ];
    }

    /** Borrower fee rows whose partner is Active ($active) or is not. */
    private function whereFeePartnerActive($query, bool $active)
    {
        $method = $active ? 'whereNotExists' : 'whereExists';

        return $query->{$method}(function ($inner) {
            $inner->selectRaw(1)
                ->from('borrowers')
                ->join('partners', 'partners.id', '=', 'borrowers.partner_id')
                ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                ->where('partners.status', '!=', Partner::STATUS_ACTIVE);
        });
    }


    /**
     * Constrain a query to User/Employee seat subscriptions.
     *
     * A seat is owned by the user or employee it was created for, and both tables
     * carry partner_id, so the partner scope is applied there.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param  ?int $partnerId Restrict to seats owned by this partner only. Null
     *   means no restriction on this end (used by the host, who is scoped instead
     *   by $excludePartnerId).
     * @param  ?int $excludePartnerId Exclude seats owned by this partner. Used by
     *   the host to see every OTHER partner's paid seats without seeing its own
     *   staff's seats — the host does not bill itself.
     */
    protected function scopeSeatSubscriptions($query, ?int $partnerId, ?int $excludePartnerId = null)
    {
        return $query->whereIn('subscriptions.entity_type', [User::class, Employee::class])
            ->when($partnerId, function ($q) use ($partnerId) {
                $q->where(function ($owner) use ($partnerId) {
                    $owner->where(function ($sub) use ($partnerId) {
                        $sub->where('subscriptions.entity_type', User::class)
                            ->whereExists(function ($inner) use ($partnerId) {
                                $inner->selectRaw(1)
                                    ->from('users')
                                    ->whereColumn('users.id', 'subscriptions.entity_id')
                                    ->where('users.partner_id', $partnerId);
                            });
                    })->orWhere(function ($sub) use ($partnerId) {
                        $sub->where('subscriptions.entity_type', Employee::class)
                            ->whereExists(function ($inner) use ($partnerId) {
                                $inner->selectRaw(1)
                                    ->from('employees')
                                    ->whereColumn('employees.id', 'subscriptions.entity_id')
                                    ->where('employees.partner_id', $partnerId);
                            });
                    });
                });
            })
            ->when($excludePartnerId, function ($q) use ($excludePartnerId) {
                $q->where(function ($owner) use ($excludePartnerId) {
                    $owner->where(function ($sub) use ($excludePartnerId) {
                        $sub->where('subscriptions.entity_type', User::class)
                            ->whereNotExists(function ($inner) use ($excludePartnerId) {
                                $inner->selectRaw(1)
                                    ->from('users')
                                    ->whereColumn('users.id', 'subscriptions.entity_id')
                                    ->where('users.partner_id', $excludePartnerId);
                            });
                    })->orWhere(function ($sub) use ($excludePartnerId) {
                        $sub->where('subscriptions.entity_type', Employee::class)
                            ->whereNotExists(function ($inner) use ($excludePartnerId) {
                                $inner->selectRaw(1)
                                    ->from('employees')
                                    ->whereColumn('employees.id', 'subscriptions.entity_id')
                                    ->where('employees.partner_id', $excludePartnerId);
                            });
                    });
                });
            });
    }

    /**
     * Get subscriber and subscription details.
     *
     * Scoped to what the caller is allowed to see. The list is partner-scoped, but
     * this endpoint was not, so any authenticated user — a borrower included — could
     * read any subscriber's name, email, phone and billing terms by changing the id
     * in the URL. The same rules the list applies are applied here.
     *
     * @param int $id The subscription ID
     * @return array|null
     */
    public function getDetails(int $id)
    {
        $authUser = auth()->user();
        $partner = $authUser?->partner ?? ($authUser?->partner_id ? Partner::find($authUser->partner_id) : null);
        $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : false;
        $userPartnerId = $partner?->id ?? $authUser?->partner_id;

        $subscription = Subscription::with(['partnerPlan', 'entity'])->find($id);

        if (!$subscription) {
            return null;
        }

        if (!$this->canViewSubscription($subscription, $isHost, $userPartnerId)) {
            // Indistinguishable from "not found" on purpose — telling the caller a
            // subscription exists but is not theirs still leaks that it exists.
            return null;
        }

        $subscriber = $subscription->entity;

        if ($subscription->entity_type === Borrower::class && $subscriber) {
            $subscriber->load('partner');
        }

        return [
            'entity_type' => $subscription->entity_name,
            'entity_id' => $subscription->entity_id,
            'subscriber' => $subscriber,
            'subscription' => new SubscriptionDetailsResource($subscription),
            // Sourced the same way as the list so both screens agree on the next
            // charge, without duplicating the derivation in the shared
            // subscription resource.
            'upcoming_billing' => (new BillingResource($subscription))->toArray(request()),
        ];
    }

    /**
     * Whether the current user may see this subscription.
     *
     * Mirrors the scoping in fetchUpcoming(): the host sees every other partner's
     * subscription and paid seats plus its own direct borrowers — never its own
     * partner-level subscription or its own staff's seats, since it does not bill
     * itself. A non-host partner sees only its own borrowers. Anyone with no
     * partner context sees nothing.
     */
    protected function canViewSubscription(Subscription $subscription, bool $isHost, ?int $userPartnerId): bool
    {
        if (!$userPartnerId) {
            return false;
        }

        return match ($subscription->entity_type) {
            Partner::class => $isHost && (int) $subscription->entity_id !== (int) $userPartnerId,
            Borrower::class => (int) (Borrower::whereKey($subscription->entity_id)->value('partner_id')) === (int) $userPartnerId,
            User::class => $isHost && (int) (User::whereKey($subscription->entity_id)->value('partner_id')) !== (int) $userPartnerId,
            Employee::class => $isHost && (int) (Employee::whereKey($subscription->entity_id)->value('partner_id')) !== (int) $userPartnerId,
            default => false,
        };
    }
}
