<?php

namespace App\Services\FCH\Admin\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use App\Services\Global\Datatable\QueryFilter;
use Illuminate\Pagination\LengthAwarePaginator;

class FundingSequencingService
{
    /**
     * Get paginated and filtered funding sequencings.
     */
    public function list(array $filters)
    {
        $query = FundingSequencing::with(['group', 'type']);

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['funding_group_id'])) {
            $query->where('funding_group_id', $filters['funding_group_id']);
        }

        if (!empty($filters['funding_type_id'])) {
            $query->where('funding_type_id', $filters['funding_type_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rawFilters = is_string($filters['filters'] ?? null) ? json_decode($filters['filters'], true) : ($filters['filters'] ?? []);
        
        if (!empty($rawFilters) && is_array($rawFilters)) {
            $groupKey = isset($rawFilters['group.name']) ? 'group.name' : (isset($rawFilters['group_name']) ? 'group_name' : null);
            if ($groupKey) {
                $groupFilters = $rawFilters[$groupKey]['constraints'] ?? [];
                foreach ($groupFilters as $c) {
                    if (!empty($c['value'])) {
                        $query->whereHas('group', function($q) use ($c) {
                            $q->where('name', 'like', '%' . $c['value'] . '%');
                        });
                    }
                }
                unset($rawFilters[$groupKey]);
            }
            
            $typeKey = isset($rawFilters['type.name']) ? 'type.name' : (isset($rawFilters['type_name']) ? 'type_name' : null);
            if ($typeKey) {
                $typeFilters = $rawFilters[$typeKey]['constraints'] ?? [];
                foreach ($typeFilters as $c) {
                    if (!empty($c['value'])) {
                        $query->whereHas('type', function($q) use ($c) {
                            $q->where('name', 'like', '%' . $c['value'] . '%');
                        });
                    }
                }
                unset($rawFilters[$typeKey]);
            }
        }

        $sortField = !empty($filters['sort_field']) ? $filters['sort_field'] : 'id';
        $rawSortOrder = $filters['sort_order'] ?? null;
        if ($rawSortOrder === 1 || $rawSortOrder === '1' || strtolower((string)$rawSortOrder) === 'asc') {
            $sortOrder = 'asc';
        } elseif ($rawSortOrder === -1 || $rawSortOrder === '-1' || strtolower((string)$rawSortOrder) === 'desc') {
            $sortOrder = 'desc';
        } else {
            $sortOrder = 'desc'; // default: newest first
        }
        
        $query = QueryFilter::apply($query, $sortField, $sortOrder, $rawFilters, ['skip_sorting' => true]);

        if ($sortField === 'group.name') {
            $query->join('funding_groups', 'funding_sequencings.funding_group_id', '=', 'funding_groups.id')
                  ->orderBy('funding_groups.name', $sortOrder)
                  ->select('funding_sequencings.*');
        } elseif ($sortField === 'type.name') {
            $query->join('funding_types', 'funding_sequencings.funding_type_id', '=', 'funding_types.id')
                  ->orderBy('funding_types.name', $sortOrder)
                  ->select('funding_sequencings.*');
        } else {
            $query->orderBy($sortField, $sortOrder);
        }

        if ($filters['per_page'] === -1) {
            return $query->get();
        }

        return $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
    }

    public function create(array $data): FundingSequencing
    {
        return FundingSequencing::create($data);
    }

    public function show($id): FundingSequencing
    {
        return FundingSequencing::with(['group', 'type'])->findOrFail($id);
    }

    public function update($id, array $data): FundingSequencing
    {
        $sequencing = FundingSequencing::findOrFail($id);
        $sequencing->update($data);

        return $sequencing;
    }

    public function delete($id): bool
    {
        $sequencing = FundingSequencing::findOrFail($id);
        return $sequencing->delete();
    }
    
    public function updateStatus($id, string $status): FundingSequencing
    {
        $sequencing = FundingSequencing::findOrFail($id);
        $sequencing->status = $status;
        $sequencing->save();
        
        return $sequencing;
    }

    /**
     * Get grouped funding sequencings for the frontend.
     */
    public function getGroupedSequencings()
    {
        return FundingGroup::with(['sequencings' => function ($query) {
            $query->where('status', 'Active')->orderBy('sort_order', 'asc');
        }, 'sequencings.type'])->where('status', 'Active')->orderBy('sort_order', 'asc')->get();
    }
}
