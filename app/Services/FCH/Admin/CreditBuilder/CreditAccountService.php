<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Models\CreditBuilder\CreditAccount;
use App\Http\Resources\FCH\CreditBuilder\CreditAccountResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

class CreditAccountService
{
    use PaginatesApi;

    /**
     * Get all credit accounts with filtering and pagination.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters = [])
    {
        $query = CreditAccount::query();

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', 'LIKE', '%' . $filters['status'] . '%');
        }

        if (!empty($filters['credit_category'])) {
            $query->where('credit_category', 'LIKE', '%' . $filters['credit_category'] . '%');
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => true, // Manual sorting below
            ]
        );

        // Accurate sorting: Primary requested field first, then sequencing_priority as tie-breaker
        if ($sortField !== 'id') {
            $query->orderBy($sortField, $sortOrder);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            CreditAccountResource::class,
            __('credit_account.credit_account_list_fetched')
        );
    }

    /**
     * Store a new credit account.
     *
     * @param array $data
     * @return CreditAccount
     */
    public function create(array $data)
    {
        return CreditAccount::create($data);
    }

    /**
     * Get a specific credit account by ID.
     *
     * @param int $id
     * @return CreditAccount
     */
    public function show($id)
    {
        return CreditAccount::findOrFail($id);
    }

    /**
     * Update a credit account.
     *
     * @param int $id
     * @param array $data
     * @return CreditAccount
     */
    public function update($id, array $data)
    {
        $account = CreditAccount::findOrFail($id);
        $account->update($data);
        return $account;
    }

    /**
     * Update the status of a credit account.
     *
     * @param int $id
     * @param string $status
     * @return CreditAccount
     */
    public function updateStatus($id, $status)
    {
        $account = CreditAccount::findOrFail($id);
        $account->status = $status;
        $account->save();
        return $account;
    }

    /**
     * Delete a credit account.
     *
     * @param int $id
     * @return bool
     */
    public function delete($id)
    {
        $account = CreditAccount::findOrFail($id);
        return $account->delete();
    }
}
