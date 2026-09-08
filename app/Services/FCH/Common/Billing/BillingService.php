<?php

namespace App\Services\FCH\Common\Billing;

use App\Models\Subscription\Subscription;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Http\Resources\FCH\Billing\BillingResource;
use App\Http\Resources\FCH\Subscription\SubscriptionDetailsResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

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

        $query = Subscription::query()
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
                'unpaidInvoices:id,subscription_id,payment_amount,status',
            ])
            // Active subscriptions, plus Pending ones that already have money owed.
            // Pending means enrolled but never paid — their signup invoice is open
            // and unpaid, so they are exactly the people who need chasing. Filtering
            // to Active alone hid the majority of the outstanding balance.
            ->where(function ($q) {
                $q->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                    ->orWhere(function ($pending) {
                        $pending->where('subscriptions.status', Subscription::STATUS_PENDING)
                            ->whereHas('unpaidInvoices');
                    });
            });

        // Host vs Non-Host Partner Scoping.
        //
        // This screen shows money owed TO the viewer, not money the viewer owes.
        // A partner's own subscription fee (Partner-type) and its own staff seat
        // fees (User/Employee-type) are both payable BY that partner TO the host —
        // that is receivable for the host, never for the partner itself, and it is
        // not receivable for the host when the entity is the host's own partner
        // record or the host's own staff (the host does not bill itself). Each
        // side's own borrowers remain the one thing it collects from directly.
        if ($isHost && $userPartnerId) {
            $query->where(function ($q) use ($userPartnerId) {
                $q->where(function ($sub) use ($userPartnerId) {
                    // Every other partner's subscription fee, never the host's own.
                    $sub->where('subscriptions.entity_type', Partner::class)
                        ->where('subscriptions.entity_id', '!=', $userPartnerId);
                })
                    ->orWhere(function ($sub) use ($userPartnerId) {
                        // The host's own direct borrowers only — a non-host partner's
                        // borrowers pay that partner, not the host.
                        $sub->where('subscriptions.entity_type', Borrower::class)
                            ->whereExists(function ($inner) use ($userPartnerId) {
                                $inner->selectRaw(1)
                                    ->from('borrowers')
                                    ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                                    ->where('borrowers.partner_id', $userPartnerId);
                            });
                    })
                    // Every other partner's paid seats (User/Employee), never the
                    // host's own staff.
                    ->orWhere(fn($sub) => $this->scopeSeatSubscriptions($sub, null, $userPartnerId));
            });
        } elseif (!$isHost && $userPartnerId) {
            // A non-host partner's own subscription and its own staff seats are
            // both money it owes the host, not money owed to it — neither belongs
            // on this screen. Its borrowers are the only receivable it has.
            $query->where(function ($sub) use ($userPartnerId) {
                $sub->where('subscriptions.entity_type', Borrower::class)
                    ->whereExists(function ($inner) use ($userPartnerId) {
                        $inner->selectRaw(1)
                            ->from('borrowers')
                            ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                            ->where('borrowers.partner_id', $userPartnerId);
                    });
            });
        }

        // Which cycles are "upcoming".
        //
        // Keyed on expected_end_date because that is the field generateDueInvoices()
        // reads — this screen exists to predict what the cron will do, so the two
        // must select on the same column. subscription_end_date is "paid through"
        // and sits in the past for anyone who has missed a cycle, which made every
        // such row show a stale date and claim it was upcoming.
        //
        // A NULL expected_end_date means nothing is scheduled, which also excludes
        // finished borrowers (PHASE_COMPLETED clears it) — they would otherwise show
        // up here forever as $0.00 rows, since status stays Active on completion.
        $query->where(function ($q) use ($targetDate) {
            $q->where(function ($sub) use ($targetDate) {
                $sub->whereNotNull('subscriptions.expected_end_date')
                    ->whereDate('subscriptions.expected_end_date', '<=', $targetDate);
            })->orWhere(function ($sub) {
                // Enrolled but never paid: no cycle is scheduled yet and their signup
                // invoice is already open. Kept on the list so staff can chase them.
                $sub->whereNull('subscriptions.expected_end_date')
                    ->whereNull('subscriptions.subscription_end_date')
                    ->whereNull('subscriptions.enrollment_completed_at');
            })->orWhereHas('unpaidInvoices');
            // Anything with money still owed, whatever its dates say. Without this
            // branch a debt could hide from every screen: a paused subscriber's
            // expected_end_date freezes, and a finished borrower's is NULL, so an
            // outstanding borrower fee on either would never be listed anywhere.
        });

        // Entity type filter. The API speaks the same vocabulary as the frontend
        // (User::PARTNER / User::BORROWER, matching ENTITY_TYPE_* in appConfig.ts)
        // and maps it to the fully-qualified model class stored in entity_type.
        $entityType = $filters['entity_type'] ?? null;

        if ($entityType === User::PARTNER) {
            $query->where('subscriptions.entity_type', Partner::class);
        } elseif ($entityType === User::BORROWER) {
            $query->where('subscriptions.entity_type', Borrower::class);
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
        $view = $filters['view'] ?? null;

        if ($view === 'paused') {
            $query->where(fn($q) => $this->wherePaused($q));
        } elseif ($view === 'overdue') {
            $query->whereNot(fn($q) => $this->wherePaused($q))
                ->whereNotNull('subscriptions.expected_end_date')
                ->whereDate('subscriptions.expected_end_date', '<', now()->toDateString());
        } elseif ($view === 'scheduled') {
            $query->whereNot(fn($q) => $this->wherePaused($q))
                ->whereNotNull('subscriptions.expected_end_date')
                ->whereDate('subscriptions.expected_end_date', '>=', now()->toDateString());
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
                if (in_array($normalized, [Subscription::PHASE_PLATFORM_FEE, Subscription::PHASE_INSTALLMENT, Subscription::PHASE_USER_SEAT])) {
                    $query->where('subscriptions.current_phase', $normalized);
                } else {
                    $query->where('subscriptions.current_phase', 'like', "%{$val}%");
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
                // column in DATE() makes the index unusable, and expected_end_date
                // is already a date column so the cast bought nothing.
                $query->where('subscriptions.expected_end_date', 'like', "%{$val}%");
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
            case 'upcoming_billing_date':
            case 'next_billing_date':
                $query->orderBy('subscriptions.expected_end_date', $sortOrder);
                $skipSorting = true;
                break;

            case 'paid_through_date':
            case 'subscription_end_date':
                $query->orderBy('subscriptions.subscription_end_date', $sortOrder);
                $skipSorting = true;
                break;

            case 'upcoming_billing_amount':
                $query->orderByRaw("
                    CASE 
                        WHEN subscriptions.current_phase = '" . Subscription::PHASE_INSTALLMENT . "' THEN subscriptions.subscription_amount_per_month 
                        WHEN subscriptions.current_phase = '" . Subscription::PHASE_PLATFORM_FEE . "' THEN subscriptions.monthly_platform_fee 
                        WHEN subscriptions.current_phase = '" . Subscription::PHASE_USER_SEAT . "' THEN subscriptions.per_seat_amount 
                        ELSE subscriptions.payable_amount 
                    END {$sortOrder}
                ");
                $skipSorting = true;
                break;

            case 'upcoming_billing_type':
                $query->orderBy('subscriptions.current_phase', $sortOrder);
                $skipSorting = true;
                break;

            case 'partner_name':
                $query->leftJoin('partners', function ($join) {
                    $join->on('subscriptions.entity_id', '=', 'partners.id')
                        ->where('subscriptions.entity_type', '=', Partner::class);
                })->orderBy('partners.full_name', $sortOrder);
                $skipSorting = true;
                break;

            case 'borrower_name':
                $query->leftJoin('borrowers', function ($join) {
                    $join->on('subscriptions.entity_id', '=', 'borrowers.id')
                        ->where('subscriptions.entity_type', '=', Borrower::class);
                })->orderByRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) {$sortOrder}");
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
        $summary = $this->summarise(clone $query);

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

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
     * Constrain to subscriptions whose billing is stopped.
     *
     * Mirrors Subscription::getIsBillingPausedAttribute() in SQL: the stamped flag
     * OR the subscriber's live status. Both are needed — the flag alone misses
     * anyone suspended since the last cron run, and the status alone misses a row
     * the cron paused for a reason that has since been cleared.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     */
    protected function wherePaused($query)
    {
        return $query->whereNotNull('subscriptions.billing_paused_at')
            ->orWhere(function ($q) {
                $q->where('subscriptions.entity_type', Partner::class)
                    ->whereExists(function ($inner) {
                        $inner->selectRaw(1)
                            ->from('partners')
                            ->whereColumn('partners.id', 'subscriptions.entity_id')
                            ->where('partners.status', '!=', Partner::STATUS_ACTIVE);
                    });
            })
            ->orWhere(function ($q) {
                // Borrower blocked by their own status, or by their partner's.
                $q->where('subscriptions.entity_type', Borrower::class)
                    ->whereExists(function ($inner) {
                        $inner->selectRaw(1)
                            ->from('borrowers')
                            ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                            ->where(function ($w) {
                                $w->where('borrowers.status', '!=', Borrower::STATUS_ACTIVE)
                                    ->orWhereExists(function ($p) {
                                        $p->selectRaw(1)
                                            ->from('partners')
                                            ->whereColumn('partners.id', 'borrowers.partner_id')
                                            ->where('partners.status', '!=', Partner::STATUS_ACTIVE);
                                    });
                            });
                    });
            });
    }

    /**
     * Totals for the whole filtered set.
     *
     * Answers the two questions this screen exists for: how much is scheduled to
     * come in, and how much is owed but cannot currently be collected because the
     * subscriber is not Active. The second number was previously invisible
     * everywhere in the system.
     */
    protected function summarise($query): array
    {
        $rows = (clone $query)
            ->with([
                'unpaidInvoices:id,subscription_id,payment_amount,status',
                // Needed by is_billing_paused, which reads the subscriber's live
                // status and not just the stamped flag.
                'partner:id,status',
                'borrower:id,partner_id,status',
                'borrower.partner:id,status',
            ])
            ->get([
                'subscriptions.id',
                'subscriptions.entity_type',
                'subscriptions.entity_id',
                'subscriptions.status',
                'subscriptions.current_phase',
                'subscriptions.billing_paused_at',
                'subscriptions.subscription_amount_per_month',
                'subscriptions.monthly_platform_fee',
                'subscriptions.per_seat_amount',
                'subscriptions.payable_amount',
                'subscriptions.expected_end_date',
            ]);

        $scheduledTotal = 0.0;
        $outstandingTotal = 0.0;
        $pausedTotal = 0.0;
        $pausedCount = 0;
        $overdueCount = 0;

        foreach ($rows as $row) {
            // Already invoiced and still owed — counted for every row, paused or
            // not, because it is real money regardless of why it is uncollected.
            $outstanding = (float) $row->unpaidInvoices->sum('payment_amount');
            $outstandingTotal += $outstanding;

            if ($row->is_billing_paused) {
                $pausedCount++;
                $pausedTotal += $outstanding;
                continue;
            }

            // Only a row with a scheduled cycle contributes to "scheduled". A
            // never-paid Pending subscriber has nothing scheduled — their money is
            // already counted as outstanding above, and counting it here too would
            // double it.
            if (!$row->expected_end_date) {
                continue;
            }

            $scheduledTotal += match ($row->current_phase) {
                Subscription::PHASE_INSTALLMENT => (float) ($row->subscription_amount_per_month ?: 0),
                Subscription::PHASE_PLATFORM_FEE => (float) ($row->monthly_platform_fee ?: 0),
                Subscription::PHASE_USER_SEAT => (float) ($row->per_seat_amount ?: 0),
                Subscription::PHASE_COMPLETED => 0.0,
                default => (float) ($row->payable_amount ?: 0),
            };

            // expected_end_date is a DATE, so it reads back as midnight. Compare
            // against today's date, not now(), or a cycle due today counts overdue.
            if ($row->expected_end_date->lt(today())) {
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
            // charge, without exposing expected_end_date through the shared
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
