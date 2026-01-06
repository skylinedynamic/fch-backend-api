<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Http\Resources\FCH\CreditBuilder\TierResource;
use App\Models\CreditBuilder\Tier;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Log;

class TierService
{
    /**
     * List Tiers with pagination and filtering.
     */
    use PaginatesApi;

    /**
     * List Tiers with pagination and filtering.
     */
    public function list(array $filters)
    {
        $query = Tier::query();


        // 🔍 Search filter
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('subtitle', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('order', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
        }
 
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sortField = $filters['sort_field'] ?? 'order';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'name' => 'tiers.name',
                    'title' => 'tiers.title',
                    'subtitle' => 'tiers.subtitle',
                    'order' => 'tiers.order',
                    'status' => 'tiers.status',
                    'description' => 'tiers.description',
                ],
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);
        return $this->respondWithPaginationResource(
            $paginator,
            TierResource::class,
            __('tier.success_list')
        );
    }

    /**
     * Store a new Tier.
     */
    public function store(array $data): Tier
    {
        $data['created_by'] = auth()->id();
        
        // Auto-assign order as next sequential number
        if (!isset($data['order']) || empty($data['order'])) {
            $maxOrder = Tier::max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        if($data['description'] == '<p><br></p>'){
            $data['description'] = null;
        }
        
        return Tier::create($data);
    }

    /**
     * Update a Tier.
     */
    public function update(Tier $tier, array $data): Tier
    {
        $data['updated_by'] = auth()->id();

        if($data['description'] == '<p><br></p>'){
            $data['description'] = null;
        }

        $tier->update($data);
        return $tier;
    }

    /**
     * Find Tier by ID.
     */
    public function findById(int $id): ?Tier
    {
        return Tier::with('tasks')->find($id);
    }

    /**
     * Delete Tiers.
     */
    public function delete(array $ids): bool
    {
        try {
            Tier::whereIn('id', $ids)->update(['deleted_by' => auth()->id()]);
            return Tier::destroy($ids) > 0;
        } catch (\Exception $e) {
            Log::error('Values deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
