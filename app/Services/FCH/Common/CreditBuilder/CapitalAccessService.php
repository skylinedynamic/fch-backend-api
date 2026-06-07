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
            __('credit_builder.capital_access_list_fetched')
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
            'name'                 => $data['name'],
            'sub_title'            => $data['sub_title'] ?? null,
            'partner_id'           => $data['partner_id'] ?? null,
            'target_exposure_min'  => $data['target_exposure_min'] ?? null,
            'target_exposure_max'  => $data['target_exposure_max'] ?? null,
            'approval_probability' => $data['approval_probability'] ?? null,
            'est_timeline_min'     => $data['est_timeline_min'] ?? null,
            'est_timeline_max'     => $data['est_timeline_max'] ?? null,
            'what_you_will_get'    => isset($data['what_you_will_get']) ? json_encode($data['what_you_will_get']) : null,
            'pre_flight_checklist' => isset($data['pre_flight_checklist']) ? json_encode($data['pre_flight_checklist']) : null,
            'status'               => $data['status'] ?? 'Active',
        ]);

        if (!empty($data['credit_accounts'])) {
            $this->syncCreditAccounts($capitalAccess, $data['credit_accounts']);
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
            'name'                 => $data['name'] ?? $capitalAccess->name,
            'sub_title'            => array_key_exists('sub_title', $data) ? $data['sub_title'] : $capitalAccess->sub_title,
            'partner_id'           => array_key_exists('partner_id', $data) ? $data['partner_id'] : $capitalAccess->partner_id,
            'target_exposure_min'  => array_key_exists('target_exposure_min', $data) ? $data['target_exposure_min'] : $capitalAccess->target_exposure_min,
            'target_exposure_max'  => array_key_exists('target_exposure_max', $data) ? $data['target_exposure_max'] : $capitalAccess->target_exposure_max,
            'approval_probability' => array_key_exists('approval_probability', $data) ? $data['approval_probability'] : $capitalAccess->approval_probability,
            'est_timeline_min'     => array_key_exists('est_timeline_min', $data) ? $data['est_timeline_min'] : $capitalAccess->est_timeline_min,
            'est_timeline_max'     => array_key_exists('est_timeline_max', $data) ? $data['est_timeline_max'] : $capitalAccess->est_timeline_max,
            'what_you_will_get'    => array_key_exists('what_you_will_get', $data) ? json_encode($data['what_you_will_get']) : $capitalAccess->what_you_will_get,
            'pre_flight_checklist' => array_key_exists('pre_flight_checklist', $data) ? json_encode($data['pre_flight_checklist']) : $capitalAccess->pre_flight_checklist,
            'status'               => $data['status'] ?? $capitalAccess->status,
        ]);

        if (array_key_exists('credit_accounts', $data)) {
            $this->syncCreditAccounts($capitalAccess, $data['credit_accounts'] ?? []);
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
     * Sync credit accounts with their sorting_order and play details on the pivot table.
     * The order of items in the array determines the sorting_order.
     *
     * @param CapitalAccess $capitalAccess
     * @param array         $creditAccounts  Array of credit_account associative arrays
     * @return void
     */
    protected function syncCreditAccounts(CapitalAccess $capitalAccess, array $creditAccounts): void
    {
        $syncData = [];

        foreach ($creditAccounts as $index => $creditAccount) {
            $syncData[$creditAccount['id']] = [
                'sorting_order'     => $index + 1,
                'why_this_card'     => $creditAccount['why_this_card'] ?? null,
                'est_approval_odds' => $creditAccount['est_approval_odds'] ?? null,
                'est_limit_min'     => $creditAccount['est_limit_min'] ?? null,
                'est_limit_max'     => $creditAccount['est_limit_max'] ?? null,
                'hard_pull'         => $creditAccount['hard_pull'] ?? 'Yes',
                'what_you_need'     => isset($creditAccount['what_you_need']) ? json_encode($creditAccount['what_you_need']) : null,
            ];
        }

        $capitalAccess->creditAccounts()->sync($syncData);
    }
}
