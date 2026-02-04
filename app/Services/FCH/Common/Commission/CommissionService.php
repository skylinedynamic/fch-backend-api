<?php

namespace App\Services\FCH\Common\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Models\Commission\Commission;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

class CommissionService
{
    use PaginatesApi;

    /**
     * Get a list of commissions.
     */
    public function getCommissions(array $filters)
    {
        $query = Commission::with(['fundingApplication', 'partner', 'borrower']);

        // Filter by Partner ID
        if (isset($filters['partner_id']) && !empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Filter by Payment Status
        if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        // Filter by Commission Status
        if (isset($filters['commission_status']) && !empty($filters['commission_status'])) {
            $query->where('commission_status', $filters['commission_status']);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'created_at' => 'date_range',
                ]
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            CommissionResource::class,
            __('commission.retrieved')
        );
    }

    /**
     * Get commission by ID with relationships.
     */
    public function getCommissionById(int $id)
    {

        return Commission::when(auth()->user()->user_type === User::PARTNER, function ($query) {
            $query->where('partner_id', auth()->user()->partner_id);
        })
            ->with(['fundingApplication', 'partner', 'borrower'])
            ->findOrFail($id);
    }
}
