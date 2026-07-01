<?php

namespace App\Services\FCH\Admin\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Pagination\LengthAwarePaginator;

class FundingSequencingService
{
    use PaginatesApi;

    /**
     * Get paginated and filtered funding sequencings.
     */
    public function list(array $filters)
    {
        $query = FundingSequencing::with(['group', 'type', 'lenderVisibilities.lender']);

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

        $sortField = !empty($filters['sort_field']) ? $filters['sort_field'] : null;
        $rawSortOrder = $filters['sort_order'] ?? null;
        if ($rawSortOrder === 1 || $rawSortOrder === '1' || strtolower((string)$rawSortOrder) === 'asc') {
            $sortOrder = 'asc';
        } elseif ($rawSortOrder === -1 || $rawSortOrder === '-1' || strtolower((string)$rawSortOrder) === 'desc') {
            $sortOrder = 'desc';
        } else {
            $sortOrder = 'desc'; // default for when a specific field is provided but no order
        }
        
        $query = QueryFilter::apply($query, $sortField ?? 'id', $sortOrder, $rawFilters, ['skip_sorting' => true]);

        if ($sortField === 'group.name') {
            $query->join('funding_groups', 'funding_sequencings.funding_group_id', '=', 'funding_groups.id')
                  ->orderBy('funding_groups.name', $sortOrder)
                  ->select('funding_sequencings.*');
        } elseif ($sortField === 'type.name') {
            $query->join('funding_types', 'funding_sequencings.funding_type_id', '=', 'funding_types.id')
                  ->orderBy('funding_types.name', $sortOrder)
                  ->select('funding_sequencings.*');
        } elseif ($sortField) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            // Default sort: sort_order ASC (nulls last), then id DESC
            $query->orderByRaw('sort_order IS NULL, sort_order ASC')->orderBy('id', 'desc');
        }

        if ($filters['per_page'] === -1) {
            return $query->get();
        }

        return $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
    }

    public function create(array $data): FundingSequencing
    {
        $lenderVisibilities = $data['lender_visibilities'] ?? [];
        unset($data['lender_visibilities'], $data['lender_id']);

        $sequencing = FundingSequencing::create($data);

        if (!empty($lenderVisibilities)) {
            $sequencing->lenderVisibilities()->createMany($lenderVisibilities);
        }
        return $sequencing->load(['group', 'type', 'lenderVisibilities.lender']);
    }

    public function show($id): FundingSequencing
    {
        return FundingSequencing::with(['group', 'type', 'lenderVisibilities.lender'])->findOrFail($id);
    }

    public function update($id, array $data): FundingSequencing
    {
        $sequencing = FundingSequencing::findOrFail($id);
        
        $lenderVisibilities = array_key_exists('lender_visibilities', $data) ? $data['lender_visibilities'] : null;
        unset($data['lender_visibilities'], $data['lender_id']);

        $sequencing->update($data);

        if ($lenderVisibilities !== null) {
            // Visibility tab payload: Update existing rows accurately without recreating them
            $existingIds = [];
            foreach ($lenderVisibilities as $visibilityData) {
                $categoryId = !empty($visibilityData['lender_product_category_id']) ? $visibilityData['lender_product_category_id'] : null;
                
                // If the frontend sent a string name instead of an integer ID, try to find it or create it
                if ($categoryId !== null && !is_numeric($categoryId)) {
                    $catModel = LenderProductCategory::firstOrCreate(
                        ['name' => $categoryId],
                        ['status' => 'Active', 'sort_order' => LenderProductCategory::max('sort_order') + 1]
                    );
                    $categoryId = $catModel->id;
                }
                
                $visibilityData['lender_product_category_id'] = $categoryId;
                $visibility = $sequencing->lenderVisibilities()->updateOrCreate(
                    [
                        'lender_id' => $visibilityData['lender_id'],
                        'lender_product_category_id' => $categoryId
                    ],
                    $visibilityData
                );
                $existingIds[] = $visibility->id;
            }
            
            // Remove any visibility rows that are no longer in the payload
            if (!empty($existingIds)) {
                $sequencing->lenderVisibilities()->whereNotIn('id', $existingIds)->delete();
            } else {
                $sequencing->lenderVisibilities()->delete();
            }
        }

        return $sequencing->load(['group', 'type', 'lenderVisibilities.lender']);
    }

    public function updateSingleLenderVisibility($id, $lenderId, array $data)
    {
        $sequencing = FundingSequencing::findOrFail($id);
        $lenderVisibilities = array_key_exists('lender_visibilities', $data) ? $data['lender_visibilities'] : [];

        if ($lenderVisibilities !== null) {
            $existingIds = [];
            foreach ($lenderVisibilities as $visibilityData) {
                // Ensure we only update for the specified lenderId
                if ((string)$visibilityData['lender_id'] !== (string)$lenderId) continue;
                
                $categoryId = !empty($visibilityData['lender_product_category_id']) ? $visibilityData['lender_product_category_id'] : null;
                $visibilityData['lender_product_category_id'] = $categoryId;
                $visibility = $sequencing->lenderVisibilities()->updateOrCreate(
                    [
                        'lender_id' => $lenderId,
                        'lender_product_category_id' => $categoryId
                    ],
                    $visibilityData
                );
                $existingIds[] = $visibility->id;
            }
            
            // Remove any visibility rows for THIS lender that are no longer in the payload
            if (!empty($existingIds)) {
                $sequencing->lenderVisibilities()->where('lender_id', $lenderId)->whereNotIn('id', $existingIds)->delete();
            } else {
                $sequencing->lenderVisibilities()->where('lender_id', $lenderId)->delete();
            }
        }

        return $this->show($id);
    }

    public function updateSingleCategoryVisibility($id, $lenderId, $categoryId, array $data)
    {
        $sequencing = FundingSequencing::findOrFail($id);
        $lenderVisibilities = array_key_exists('lender_visibilities', $data) ? $data['lender_visibilities'] : [];

        // Normalize category ID: if 'general' or '0' or empty, treat as null
        $targetCategoryId = ($categoryId === 'general' || $categoryId === '0' || empty($categoryId)) ? null : $categoryId;

        if ($lenderVisibilities !== null) {
            $existingIds = [];
            foreach ($lenderVisibilities as $visibilityData) {
                // Ensure we only update for the specified lenderId and categoryId
                if ((string)$visibilityData['lender_id'] !== (string)$lenderId) continue;
                
                $vCatId = !empty($visibilityData['lender_product_category_id']) ? $visibilityData['lender_product_category_id'] : null;
                
                // Compare values
                $vCatIdStr = $vCatId !== null ? (string)$vCatId : null;
                $targetCatIdStr = $targetCategoryId !== null ? (string)$targetCategoryId : null;
                if ($vCatIdStr !== $targetCatIdStr) continue;

                $visibility = $sequencing->lenderVisibilities()->updateOrCreate(
                    [
                        'lender_id' => $lenderId,
                        'lender_product_category_id' => $targetCategoryId
                    ],
                    $visibilityData
                );
                $existingIds[] = $visibility->id;
            }
            
            // Remove any visibility rows for THIS lender AND THIS category that are no longer in the payload
            if (empty($existingIds)) {
                $query = $sequencing->lenderVisibilities()->where('lender_id', $lenderId);
                if ($targetCategoryId === null) {
                    $query->whereNull('lender_product_category_id');
                } else {
                    $query->where('lender_product_category_id', $targetCategoryId);
                }
                $query->delete();
            }
        }

        return $this->show($id);
    }

    public function deleteVisibilities($id, array $visibilityIds): void
    {
        $sequencing = FundingSequencing::findOrFail($id);
        $sequencing->lenderVisibilities()->whereIn('id', $visibilityIds)->delete();
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
     * Get paginated visibilities for a funding sequence.
     */
    public function getPaginatedVisibilities($id, array $filters)
    {
        $query = LenderVisibility::with(['lender', 'productCategory'])
            ->where('funding_sequencing_id', $id)
            ->select('lender_visibilities.*');

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['lender_id'])) {
            $query->where('lender_id', $filters['lender_id']);
        }

        if (!empty($filters['lender_product_category_id'])) {
            $query->where('lender_product_category_id', $filters['lender_product_category_id']);
        }

        $sortField = $filters['sortField'] ?? 'id';
        $sortOrder = ($filters['sortOrder'] ?? -1) == 1 ? 'asc' : 'desc';

        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        $skipSorting = false;
        if ($sortField === '_lenderName') {
            $query->orderBy(
                Lender::select('name')
                    ->whereColumn('lenders.id', 'lender_visibilities.lender_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        } elseif ($sortField === '_categoryName') {
            $query->orderBy(
                LenderProductCategory::select('name')
                    ->whereColumn('lender_product_categories.id', 'lender_visibilities.lender_product_category_id')
                    ->limit(1),
                $sortOrder
            );
            $skipSorting = true;
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting,
                'aliases' => [],
            ]
        );

        $perPage = isset($filters['rows']) ? max(1, (int) $filters['rows']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPagination(
            $paginator,
            __('funding_sequencing.visibilities_fetched'),
            200
        );
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
