<?php

namespace App\Services\FCH\Common\Billing;

use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
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

        $targetDate = now()->addDays(10)->toDateString();

        $query = Subscription::query()
            ->with([
                'partner:id,full_name,company_name,email',
                'borrower:id,first_name,last_name,email,partner_id',
                'borrower.partner:id,full_name,company_name,email',
            ]);

        // Host vs Non-Host Partner Scoping:
        // Host gets all partners and borrowers belonging to current user partner ID
        if ($isHost && $userPartnerId) {
            $query->where(function ($q) use ($userPartnerId) {
                $q->where('subscriptions.entity_type', Partner::class)
                  ->orWhere(function ($sub) use ($userPartnerId) {
                      $sub->where('subscriptions.entity_type', Borrower::class)
                          ->whereExists(function ($inner) use ($userPartnerId) {
                              $inner->selectRaw(1)
                                  ->from('borrowers')
                                  ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                                  ->where('borrowers.partner_id', $userPartnerId);
                          });
                  });
            });
        } elseif (!$isHost && $userPartnerId) {
            $query->where(function ($q) use ($userPartnerId) {
                $q->where(function ($sub) use ($userPartnerId) {
                    $sub->where('subscriptions.entity_type', Partner::class)
                        ->where('subscriptions.entity_id', $userPartnerId);
                })->orWhere(function ($sub) use ($userPartnerId) {
                    $sub->where('subscriptions.entity_type', Borrower::class)
                        ->whereExists(function ($inner) use ($userPartnerId) {
                            $inner->selectRaw(1)
                                ->from('borrowers')
                                ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                                ->where('borrowers.partner_id', $userPartnerId);
                        });
                });
            });
        }

        // Subscription end date & enrollment completed rules:
        // - check who's subscription end date is finish within 10 days
        // - if subscription end date is null and enrollment_completed_at is not null do not take it
        $query->where(function ($q) use ($targetDate) {
            $q->where(function ($sub) use ($targetDate) {
                $sub->whereNotNull('subscriptions.subscription_end_date')
                    ->whereDate('subscriptions.subscription_end_date', '<=', $targetDate);
            })->orWhere(function ($sub) {
                $sub->whereNull('subscriptions.subscription_end_date')
                    ->whereNull('subscriptions.enrollment_completed_at');
            });
        });

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
                $query->whereDate('subscriptions.subscription_end_date', 'like', "%{$val}%");
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

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BillingResource::class,
            __('billing.upcoming_retrieved')
        );
    }

    /**
     * Get subscriber and subscription details.
     *
     * @param int $id The subscription ID
     * @return array|null
     */
    public function getDetails(int $id)
    {
        $subscription = Subscription::with(['enrollmentPhase', 'platformFeePhase', 'partnerPlan', 'entity'])
            ->find($id);

        if (!$subscription) {
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
        ];
    }
}
