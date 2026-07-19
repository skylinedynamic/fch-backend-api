<?php

namespace App\Services\FCH\Common\Billing;

use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Http\Resources\FCH\Billing\BillingResource;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;
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
        $targetDate = now()->addDays(config('subscription.generate_before_days', 5));

        $query = SubscriptionPhase::query()
            ->join('subscriptions', 'subscription_phases.subscription_id', '=', 'subscriptions.id')
            ->select('subscription_phases.*')
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) use ($targetDate) {
                $q->where(function ($sub) use ($targetDate) {
                    $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_ENROLLMENT)
                        ->whereNotNull('subscription_phases.next_installment_date')
                        ->whereDate('subscription_phases.next_installment_date', '<=', $targetDate);
                })->orWhere(function ($sub) use ($targetDate) {
                    $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_PLATFORM_FEE)
                        ->whereNotNull('subscription_phases.next_platform_fee_date')
                        ->whereDate('subscription_phases.next_platform_fee_date', '<=', $targetDate);
                });
            })
            ->with([
                'subscription.partner:id,full_name,company_name,email',
                'subscription.borrower:id,first_name,last_name,email,partner_id',
                'subscription.borrower.partner:id,full_name,company_name,email',
            ]);

        // Global Search
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        if (!empty($globalSearch)) {
            $query->whereHas('subscription', function ($q) use ($globalSearch) {
                $q->search($globalSearch);
            });
        }

        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']);

        // Custom Filters for Columns
        if (!empty($rawFilters['partner_name'])) {
            $val = $rawFilters['partner_name']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where(function ($q) use ($val) {
                    $q->whereHas('subscription.partner', function ($sub) use ($val) {
                        $sub->where('full_name', 'like', "%{$val}%")
                            ->orWhere('company_name', 'like', "%{$val}%");
                    })->orWhereHas('subscription.borrower', function ($sub) use ($val) {
                        $sub->whereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$val}%"]);
                    });
                });
            }
            unset($rawFilters['partner_name']);
        }

        if (!empty($rawFilters['upcoming_billing_type'])) {
            $val = $rawFilters['upcoming_billing_type']['constraints'][0]['value'] ?? null;
            if ($val) {
                if ($val === 'Platform Fee') {
                    $query->where('subscription_phases.phase', SubscriptionPhase::PHASE_PLATFORM_FEE);
                } else {
                    $query->where('subscription_phases.phase', SubscriptionPhase::PHASE_ENROLLMENT);
                }
            }
            unset($rawFilters['upcoming_billing_type']);
        }

        if (!empty($rawFilters['upcoming_billing_amount'])) {
            $val = $rawFilters['upcoming_billing_amount']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where(function ($q) use ($val) {
                    $q->where(function ($sub) use ($val) {
                        $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_PLATFORM_FEE)
                            ->where('subscriptions.monthly_platform_fee', 'like', "%{$val}%");
                    })->orWhere(function ($sub) use ($val) {
                        $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_ENROLLMENT)
                            ->where('subscriptions.payable_amount', 'like', "%{$val}%");
                    });
                });
            }
            unset($rawFilters['upcoming_billing_amount']);
        }

        if (!empty($rawFilters['upcoming_billing_date'])) {
            $val = $rawFilters['upcoming_billing_date']['constraints'][0]['value'] ?? null;
            if ($val) {
                $query->where(function ($q) use ($val) {
                    $q->where(function ($sub) use ($val) {
                        $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_PLATFORM_FEE)
                            ->where('subscription_phases.next_platform_fee_date', 'like', "%{$val}%");
                    })->orWhere(function ($sub) use ($val) {
                        $sub->where('subscription_phases.phase', SubscriptionPhase::PHASE_ENROLLMENT)
                            ->where('subscription_phases.next_installment_date', 'like', "%{$val}%");
                    });
                });
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
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $skipSorting = false;

        switch ($sortField) {
            case 'upcoming_billing_date':
            case 'next_billing_date':
                $query->orderByRaw('COALESCE(subscription_phases.next_installment_date, subscription_phases.next_platform_fee_date) ' . $sortOrder);
                $skipSorting = true;
                break;

            case 'upcoming_billing_amount':
                $query->orderByRaw('CASE WHEN subscription_phases.phase = "platform_fee" THEN subscriptions.monthly_platform_fee ELSE subscriptions.payable_amount END ' . $sortOrder);
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
                $query->orderBy('subscription_phases.id', $sortOrder);
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
            'subscription' => new SubscriptionResource($subscription),
        ];
    }
}
