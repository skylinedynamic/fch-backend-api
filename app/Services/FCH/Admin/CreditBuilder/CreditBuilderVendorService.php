<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Http\Resources\FCH\CreditBuilder\CreditBuilderVendorResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

class CreditBuilderVendorService
{

    use PaginatesApi;
    

    /**
     * Get all vendors with filtering and pagination.
     *
     * @param array $filters
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function list(array $filters = [])
    {
        $query = CreditBuilderVendor::query();

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if(isset($filters['tier_id']) && !empty($filters['tier_id'])) {
            $query->where('tier_id', $filters['tier_id']);
        }

        if(isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;


        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => $skipSorting,
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            CreditBuilderVendorResource::class,
            __('credit_builder.vendor_list_fetched')
        );

    }

    /**
     * Store a new vendor.
     *
     * @param array $data
     * @return CreditBuilderVendor
     */
    public function create(array $data)
    {
        return CreditBuilderVendor::create($data);
    }

    /**
     * Get a specific vendor by ID.
     *
     * @param int $id
     * @return CreditBuilderVendor
     */
    public function show($id)
    {
        return CreditBuilderVendor::findOrFail($id);
    }

    /**
     * Update a vendor.
     *
     * @param int $id
     * @param array $data
     * @return CreditBuilderVendor
     */
    public function update($id, array $data)
    {
        $vendor = CreditBuilderVendor::findOrFail($id);
        $vendor->update($data);
        return $vendor;
    }

    /**
     * Delete a vendor.
     *
     * @param int $id
     * @return bool
     */
    public function delete($id)
    {
        $vendor = CreditBuilderVendor::findOrFail($id);
        return $vendor->delete();
    }
}
