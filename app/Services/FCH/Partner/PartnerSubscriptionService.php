<?php

namespace App\Services\FCH\Partner;

use Illuminate\Support\Str;
use App\Models\Partner\PartnerSubscription;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Http\Resources\FCH\Partner\PartnerSubscriptionResource;
use Illuminate\Support\Facades\Log;

class PartnerSubscriptionService
{
    use PaginatesApi;

    /**
     * List partner subscriptions with pagination and filters.
     *
     * @param array $filters
     * @param int $partnerId
     * @return PartnerSubscriptionResource
     */
    public function list(array $filters, int $partnerId)
    {
        $query = $this->prepareSubscriptionQuery($filters, $partnerId);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'));
    }

    /**
     * Fetch All Subscriptions (Global).
     *
     * @param array $filters
     * @return PartnerSubscriptionResource
     */
    public function fetchAll(array $filters)
    {
        $query = $this->prepareSubscriptionQuery($filters, null);
        return $this->paginateAndRespond($query, $filters, __('partner.subscription_list_retrieved'));
    }

    /**
     * Prepare the subscription query with filters and sorting.
     */
    private function prepareSubscriptionQuery(array $filters, ?int $partnerId = null)
    {
        $query = PartnerSubscription::where('partner_id', $filters['partner_id'])
            ->with(['partner:id,full_name,company_name,email'])
            ->select('partner_subscriptions.*');

        if ($partnerId) {
            $query->where('partner_id', $partnerId);
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
            $query->leftJoin('partners', 'partner_subscriptions.partner_id', '=', 'partners.id')
                ->addSelect('partners.full_name as partner_name');
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
            PartnerSubscriptionResource::class,
            $message
        );
    }

    /**
     * Create or Update Subscription
     *
     * @param int $partnerId
     * @param array $data
     * @return PartnerSubscription
     */
    public function createOrUpdate(int $partnerId, array $data)
    {
        // Handle installment_type logic
        if (isset($data['enrollment_type'])) {
            $data['installment_type'] = (Str::contains($data['enrollment_type'], ['Payment Plan', 'Monthly'])) ? 1 : 0;
        }

        return PartnerSubscription::updateOrCreate(
            ['partner_id' => $partnerId],
            $data
        );
    }

    /**
     * Get Subscription by Partner ID
     *
     * @param int $partnerId
     * @return PartnerSubscription|null
     */
    public function getByPartnerId(int $partnerId)
    {
        $subscription = PartnerSubscription::where('partner_id', $partnerId)->orderBy('id', 'desc')->with(['partner'])->first();
        return $subscription;
    }
}
