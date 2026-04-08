<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Models\CreditBuilder\VendorAccount;
use App\Http\Resources\FCH\CreditBuilder\VendorAccountResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

class VendorAccountService
{
    use PaginatesApi;

    /**
     * Get all vendor accounts with filtering and pagination.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters = [])
    {
        $query = VendorAccount::query();

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => false,
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            VendorAccountResource::class,
            __('credit_builder.vendor_account_list_fetched')
        );
    }

    /**
     * Store a new vendor account.
     *
     * @param array $data
     * @return VendorAccount
     */
    public function create(array $data)
    {
        return VendorAccount::create($data);
    }

    /**
     * Get a specific vendor account by ID.
     *
     * @param int $id
     * @return VendorAccount
     */
    public function show($id)
    {
        return VendorAccount::findOrFail($id);
    }

    /**
     * Update a vendor account.
     *
     * @param int $id
     * @param array $data
     * @return VendorAccount
     */
    public function update($id, array $data)
    {
        $account = VendorAccount::findOrFail($id);
        $account->update($data);
        return $account;
    }

    /**
     * Delete a vendor account.
     *
     * @param int $id
     * @return bool
     */
    public function delete($id)
    {
        $account = VendorAccount::findOrFail($id);
        return $account->delete();
    }
}
