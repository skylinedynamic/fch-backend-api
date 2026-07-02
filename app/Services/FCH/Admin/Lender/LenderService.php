<?php

namespace App\Services\FCH\Admin\Lender;

use App\Models\Lender\Lender;
use App\Traits\PaginatesApi;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Lender\LenderResource;

class LenderService
{
    use PaginatesApi;

    /**
     * List lenders with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        $query = Lender::query()->select('lenders.*');

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Manual column filters handling
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => false,
                'aliases' => [
                    'name' => 'lenders.name',
                    'legal_name' => 'lenders.legal_name',
                    'contact_name' => 'lenders.contact_name',
                    'contact_email' => 'lenders.contact_email',
                    'status' => 'lenders.status',
                    'created_at' => 'date_range',
                ]
            ]
        );

        $perPage = $filters['per_page'] ?? 10;
        if ($perPage == -1 || $perPage == 'All') {
            $items = $query->get();
            $meta = [];
            // Optional: return all items but match the structure. 
            // In PaginatesApi there isn't a direct respondWithAllResource but we can use respondWithPaginationResource by faking a paginator, 
            // OR we can just use the paginateBuilderWith which handles large perPage if we pass a large number.
            // But let's build a paginator with the total count.
            $perPage = $items->count() > 0 ? $items->count() : 10;
        }

        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page']) ? max(1, (int) $filters['per_page']) : (is_numeric($perPage) ? max(1, (int) $perPage) : 1000000);
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            LenderResource::class,
            __('lender.fetched_successfully'),
            200
        );
    }

    /**
     * Get all active lenders (id and name only).
     */
    public function getAllActiveLenders()
    {
        return Lender::where('status', Lender::STATUS_ACTIVE)
            ->select('id', 'name', 'legal_name', 'status', 'product_categories', 'contact_phone')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Store a new lender.
     */
    public function store(array $data)
    {
        return Lender::create($data);
    }

    /**
     * Update an existing lender.
     */
    public function update(Lender $lender, array $data)
    {
        $lender->update($data);
        return $lender;
    }

    /**
     * Delete a lender.
     */
    public function delete(Lender $lender)
    {
        // Delete all related visibilities when a lender is deleted
        $lender->visibilities()->delete();
        
        return $lender->delete();
    }
}
