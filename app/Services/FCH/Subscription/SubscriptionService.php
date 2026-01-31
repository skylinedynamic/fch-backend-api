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

class SubscriptionService
{
    use PaginatesApi;

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
}
