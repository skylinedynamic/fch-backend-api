<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Exceptions\CreditAccountAppliedException;
use App\Models\CreditBuilder\CreditAccount;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\FCH\CreditBuilder\CreditAccountResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;

class CreditAccountService
{
    use PaginatesApi, UploadsFiles;

    /**
     * Get all credit accounts with filtering and pagination.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters = [])
    {
        $query = CreditAccount::query()->withApplicationFlags();

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
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

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

        // Ensure this sorting replaces any preceding order clauses.
        $query->reorder($sortField, $sortOrder);

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
        if (isset($data['image_url']) && $data['image_url'] instanceof \Illuminate\Http\UploadedFile) {
            $upload = $this->uploadFile($data['image_url'], 'credit-accounts', 'public');
            $data['image_url'] = $upload['path'];
        }

        return CreditAccount::create($data);
    }

    /**
     * Get ALL active credit accounts without pagination (for builder panels / dropdowns).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function allActive($search = null)
    {
        $query = CreditAccount::withApplicationFlags()->where('status', CreditAccount::STATUS_ACTIVE);
        if (!empty($search)) {
            $query->search($search);
        }
        return CreditAccountResource::collection(
            $query->orderBy('card_name', 'asc')
                ->get()
        );
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

        if (isset($data['image_url']) && $data['image_url'] instanceof \Illuminate\Http\UploadedFile) {
            // Delete old file if exists
            if ($account->image_url) {
                $this->deleteFile($account->image_url, 'public');
            }

            $upload = $this->uploadFile($data['image_url'], 'credit-accounts', 'public');
            $data['image_url'] = $upload['path'];
        }

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
        CreditAccount::findOrFail($id);

        return $this->deleteMultiple([(int) $id]) === 1;
    }

    /**
     * Deletes the selected cards (soft delete). A card a borrower has applied
     * for is kept: if the selection has one, nothing is deleted and those
     * cards are named.
     *
     * @param  int[]  $ids
     * @return int how many were deleted
     *
     * @throws CreditAccountAppliedException
     */
    public function deleteMultiple(array $ids): int
    {
        $accounts = CreditAccount::withApplicationFlags()->whereIn('id', $ids)->get();

        $applied = $accounts->filter(fn (CreditAccount $account) => $account->hasBorrowerApplications());
        if ($applied->isNotEmpty()) {
            throw new CreditAccountAppliedException(
                $accounts->count() === 1
                    ? __('credit_account.delete_blocked_applied')
                    : __('credit_account.delete_blocked_applied_named', ['names' => $applied->pluck('card_name')->implode(', ')]),
                $applied->map(fn (CreditAccount $a) => ['id' => $a->id, 'name' => $a->card_name])->values()->all()
            );
        }

        return DB::transaction(function () use ($accounts) {
            $accounts->each(fn (CreditAccount $account) => $account->delete());

            return $accounts->count();
        });
    }
}
