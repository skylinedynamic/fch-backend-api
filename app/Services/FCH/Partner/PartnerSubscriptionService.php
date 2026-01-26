<?php

namespace App\Services\FCH\Partner;

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
        $query = PartnerSubscription::query()
            ->where('partner_id', $partnerId)
            ->with(['partner:id,full_name,company_name,email'])
            ->select('partner_subscriptions.*');

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

        $query = QueryFilter::apply(
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

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PartnerSubscriptionResource::class,
            __('partner.subscription_list_retrieved')
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
            $data['installment_type'] = ($data['enrollment_type'] === 'Payment Plan' || $data['enrollment_type'] === 'Monthly') ? 1 : 0;
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
        $subscription = PartnerSubscription::where('partner_id', $partnerId)->with(['partner'])->first();
        return $subscription;
    }
}
