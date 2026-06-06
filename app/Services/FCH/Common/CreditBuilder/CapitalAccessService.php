<?php

namespace App\Services\FCH\Common\CreditBuilder;

use App\Models\CreditBuilder\CapitalAccess;
use App\Http\Resources\FCH\CreditBuilder\CapitalAccessResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;

class CapitalAccessService
{
    use PaginatesApi;

    /**
     * Get all capital accesses with filtering and pagination.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters = [])
    {
        $query = CapitalAccess::with('creditAccounts');

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', 'LIKE', '%' . $filters['status'] . '%');
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'desc' ? 'asc' : 'desc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases'      => [],
                'skip_sorting' => true,
            ]
        );

        $query->reorder($sortField, $sortOrder);

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page    = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            CapitalAccessResource::class,
            'Capital access list fetched successfully.'
        );
    }

    /**
     * Store a new capital access with its credit account sequence.
     *
     * @param array $data
     * @return CapitalAccess
     */
    public function create(array $data): CapitalAccess
    {
        $capitalAccess = CapitalAccess::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'Active',
        ]);

        if (!empty($data['credit_account_ids'])) {
            $this->syncCreditAccounts($capitalAccess, $data['credit_account_ids']);
        }

        return $capitalAccess->load('creditAccounts');
    }

    /**
     * Get a specific capital access by ID with its credit accounts.
     *
     * @param int $id
     * @return CapitalAccess
     */
    public function show($id): CapitalAccess
    {
        return CapitalAccess::with('creditAccounts')->findOrFail($id);
    }

    /**
     * Update a capital access and sync its credit account sequence.
     *
     * @param int   $id
     * @param array $data
     * @return CapitalAccess
     */
    public function update($id, array $data): CapitalAccess
    {
        $capitalAccess = CapitalAccess::findOrFail($id);

        $capitalAccess->update([
            'name'        => $data['name']        ?? $capitalAccess->name,
            'description' => $data['description'] ?? $capitalAccess->description,
            'status'      => $data['status']       ?? $capitalAccess->status,
        ]);

        if (array_key_exists('credit_account_ids', $data)) {
            $this->syncCreditAccounts($capitalAccess, $data['credit_account_ids'] ?? []);
        }

        return $capitalAccess->load('creditAccounts');
    }

    /**
     * Update the status of a capital access.
     *
     * @param int    $id
     * @param string $status
     * @return CapitalAccess
     */
    public function updateStatus($id, string $status): CapitalAccess
    {
        $capitalAccess = CapitalAccess::findOrFail($id);
        $capitalAccess->status = $status;
        $capitalAccess->save();

        return $capitalAccess->load('creditAccounts');
    }

    /**
     * Delete a capital access (soft delete).
     *
     * @param int $id
     * @return bool
     */
    public function delete($id): bool
    {
        $capitalAccess = CapitalAccess::findOrFail($id);
        // Detach all credit accounts before soft deleting
        $capitalAccess->creditAccounts()->detach();
        return $capitalAccess->delete();
    }

    /**
     * Sync credit accounts with their sorting_order on the pivot table.
     * The order of IDs in the array determines the sorting_order.
     *
     * @param CapitalAccess $capitalAccess
     * @param array         $creditAccountIds  Ordered array of credit_account IDs
     * @return void
     */
    protected function syncCreditAccounts(CapitalAccess $capitalAccess, array $creditAccountIds): void
    {
        $syncData = [];

        foreach ($creditAccountIds as $index => $creditAccountId) {
            $syncData[$creditAccountId] = ['sorting_order' => $index + 1];
        }

        $capitalAccess->creditAccounts()->sync($syncData);
    }
}
