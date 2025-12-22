<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Http\Resources\FCH\CreditBuilder\TierTaskResource;
use App\Models\CreditBuilder\TierTask;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Log;

class TierTaskService
{
    use PaginatesApi;

    /**
     * List Tier Tasks with pagination and filtering.
     */
    public function list(array $filters)
    {
        $query = TierTask::query()->with('tier:id,name');

        // 🔍 Search filter
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('tier_tasks.title', 'like', "%{$search}%")
                  ->orWhere('tier_tasks.subtitle', 'like', "%{$search}%")
                  ->orWhere('tier_tasks.description', 'like', "%{$search}%")
                  ->orWhere('tier_tasks.button_title', 'like', "%{$search}%")
                  ->orWhere('tier_tasks.url', 'like', "%{$search}%");
            });
        }

        if (isset($filters['status'])) {
            $query->where('tier_tasks.status', $filters['status']);
        }

        if (isset($filters['tier_id'])) {
            $query->where('tier_tasks.tier_id', $filters['tier_id']);
        }

        $sortField = $filters['sort_field'] ?? 'order';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // Check if we need to join for sorting or filtering
        $needsJoin = ($sortField === 'tier_name');
        if (!$needsJoin && !empty($filters['filters'])) {
            $rawFilters = $filters['filters'];
            if (is_string($rawFilters)) {
                $decoded = json_decode($rawFilters, true);
                if (is_array($decoded)) $rawFilters = $decoded;
            }
            if (is_array($rawFilters) && isset($rawFilters['tier_name'])) {
                $needsJoin = true;
            }
        }

        if ($needsJoin) {
            $query->leftJoin('tiers', 'tier_tasks.tier_id', '=', 'tiers.id')
                  ->select('tier_tasks.*', 'tiers.name as tier_name');
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'tier_name' => 'tiers.name',
                ]
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

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
             // If ordering is global, remove the where clause. Assuming per-tier ordering.
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
            Log::error('Tier tasks deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
