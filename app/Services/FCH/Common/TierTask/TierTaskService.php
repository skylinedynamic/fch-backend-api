<?php

namespace App\Services\FCH\Common\TierTask;

use App\Traits\PaginatesApi;
use App\Models\CreditBuilder\TierTask;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\CreditBuilder\TierTaskResource;

class TierTaskService
{
    use PaginatesApi;

    /**
     * List all tier tasks with id and title.
     * Can be filtered by tier_id and search.
     */
    public function list(array $filters = [])
    {
        $sortField = $filters['sort_field'] ?? 'order';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // Map sorting aliases for project consistency
        $sortAliases = [
            'full_name' => 'title',
        ];
        $actualSortField = $sortAliases[$sortField] ?? $sortField;

        $query = TierTask::query()
            ->select('id', 'tier_id', 'title', 'subtitle', 'description', 'url', 'button_title', 'status', 'order', 'estimated_completion_days');


        // 🔍 Search filter
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('subtitle', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['tier_id'])) {
            $query->where('tier_id', $filters['tier_id']);
        }

        // Apply project-standard QueryFilter for sorting and column-specific filtering
        $query = QueryFilter::apply(
            $query,
            $actualSortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => $sortAliases
            ]
        );

        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            TierTaskResource::class,
            __('tier_task.success_list')
        );
    }

    /**
     * Store a new Tier Task.
     */
    public function store(array $data): TierTask
    {
        $data['created_by'] = auth()->id();

        // Auto-assign order as next sequential number within the tier
        if (!isset($data['order']) || empty($data['order'])) {
            $maxOrder = TierTask::where('tier_id', $data['tier_id'])->max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        return TierTask::create($data);
    }

    /**
     * Update a Tier Task.
     */
    public function update(TierTask $tierTask, array $data): TierTask
    {
        $data['updated_by'] = auth()->id();
        $tierTask->update($data);
        return $tierTask;
    }

    /**
     * Find Tier Task by ID.
     */
    public function findById(int $id): ?TierTask
    {
        return TierTask::with('tier')->find($id);
    }

    /**
     * Delete Tier Tasks.
     */
    public function delete(array $ids): bool
    {
        try {
            TierTask::whereIn('id', $ids)->update(['deleted_by' => auth()->id()]);
            return TierTask::destroy($ids) > 0;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Tier tasks deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
