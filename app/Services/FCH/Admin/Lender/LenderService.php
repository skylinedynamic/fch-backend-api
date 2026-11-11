<?php

namespace App\Services\FCH\Admin\Lender;

use App\Exceptions\LenderInUseException;
use App\Exceptions\LenderProductCategoryInUseException;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Support\Facades\DB;
use App\Traits\PaginatesApi;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Lender\LenderResource;

class LenderService
{
    use PaginatesApi;

    /**
     * List lenders with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        $query = Lender::query()->select('lenders.*');

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Manual column filters handling
        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => false,
                'aliases' => [
                    'name' => 'lenders.name',
                    'lender_code' => 'lenders.lender_code',
                    'legal_name' => 'lenders.legal_name',
                    'contact_name' => 'lenders.contact_name',
                    'contact_email' => 'lenders.contact_email',
                    'status' => 'lenders.status',
                    'created_at' => 'date_range',
                ]
            ]
        );

        $perPage = $filters['per_page'] ?? 10;
        if ($perPage == -1 || $perPage == 'All') {
            $items = $query->get();
            $meta = [];
            // Optional: return all items but match the structure. 
            // In PaginatesApi there isn't a direct respondWithAllResource but we can use respondWithPaginationResource by faking a paginator, 
            // OR we can just use the paginateBuilderWith which handles large perPage if we pass a large number.
            // But let's build a paginator with the total count.
            $perPage = $items->count() > 0 ? $items->count() : 10;
        }

        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page']) ? max(1, (int) $filters['per_page']) : (is_numeric($perPage) ? max(1, (int) $perPage) : 1000000);
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            LenderResource::class,
            __('lender.fetched_successfully'),
            200
        );
    }

    /**
     * Get all active lenders (id and name only).
     */
    public function getAllActiveLenders()
    {
        return Lender::where('status', Lender::STATUS_ACTIVE)
            ->select('id', 'lender_code', 'name', 'legal_name', 'status', 'product_categories', 'contact_phone')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * The lenders that already have this name, and those with this legal name
     * (case and spacing ignored) - Add/Edit Lender warns, it does not refuse.
     *
     * @return array{name: array<int, array<string, mixed>>, legal_name: array<int, array<string, mixed>>}
     */
    public function duplicates(array $data): array
    {
        $ignoreId = $data['ignore_id'] ?? null;

        return [
            'name' => Lender::withSame('name', $data['name'] ?? null, $ignoreId)->all(),
            'legal_name' => Lender::withSame('legal_name', $data['legal_name'] ?? null, $ignoreId)->all(),
        ];
    }

    /**
     * Store a new lender.
     */
    public function store(array $data)
    {
        return Lender::create($data);
    }

    /**
     * Update an existing lender.
     */
    public function update(Lender $lender, array $data)
    {
        $lender->update($data);
        return $lender;
    }

    /**
     * Delete a lender.
     */
    public function delete(Lender $lender)
    {
        return $this->deleteMultiple([$lender->id]);
    }

    /**
     * Delete the selected lenders, all or none. A lender still in use is kept:
     * the whole selection is refused and each blocked lender is named - same
     * rule as clients and partners.
     *
     * @throws LenderInUseException
     */
    public function deleteMultiple(array $ids): int
    {
        $lenders = Lender::whereIn('id', $ids)->get();

        $blocked = $lenders->reject(fn (Lender $lender) => $lender->isDeletable());
        if ($blocked->isNotEmpty()) {
            throw new LenderInUseException(
                $lenders->count() === 1
                    ? __('lender.delete_blocked')
                    // Named as on screen, "Lender Name - Legal Name" (R2 BUG-008).
                    : __('lender.delete_blocked_named', ['names' => $blocked->map(fn (Lender $l) => $l->displayName())->implode(', ')]),
                $blocked->map(fn (Lender $l) => ['id' => $l->id, 'name' => $l->name, 'display_name' => $l->displayName()])->values()->all()
            );
        }

        return DB::transaction(function () use ($lenders) {
            foreach ($lenders as $lender) {
                $lender->delete();
            }

            return $lenders->count();
        });
    }

    /** Whether each lender can be deleted, with the reason when it cannot. */
    public function checkDeletable(array $ids): array
    {
        return Lender::whereIn('id', $ids)->get()->map(fn (Lender $lender) => [
            'id' => $lender->id,
            'name' => $lender->name,
            'display_name' => $lender->displayName(),
            'is_deletable' => $deletable = $lender->isDeletable(),
            'reason' => $deletable ? null : $lender->notDeletableReason(),
        ])->values()->all();
    }

    /**
     * Delete a product category no funding sequence uses. One still in the
     * underwriting logic of a live funding sequence
     * (lender_visibilities.lender_product_category_id) is kept, like a blog
     * tag still on a post; a deleted sequence no longer counts.
     *
     * @throws LenderProductCategoryInUseException
     */
    public function deleteProductCategory(LenderProductCategory $category): void
    {
        $sequences = $this->fundingSequencesUsing()
            ->where('lender_product_category_id', $category->id)
            ->count('lender_visibilities.funding_sequencing_id');

        if ($sequences > 0) {
            throw new LenderProductCategoryInUseException(__('lender.category_in_use'), [
                ['id' => $category->id, 'name' => $category->name, 'count' => $sequences],
            ]);
        }

        $category->delete();
    }

    /**
     * Every product category, each with funding_sequences_count: how many live
     * funding sequences use it in their underwriting logic - so the portal can
     * refuse a delete at once instead of asking "Are you sure?" first.
     */
    public function productCategoriesWithUsage()
    {
        $usage = $this->fundingSequencesUsing()
            ->whereColumn('lender_visibilities.lender_product_category_id', 'lender_product_categories.id')
            ->selectRaw('COUNT(DISTINCT lender_visibilities.funding_sequencing_id)');

        return LenderProductCategory::query()
            ->select('lender_product_categories.*')
            ->selectSub($usage, 'funding_sequences_count')
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get()
            ->each(fn (LenderProductCategory $category) => $category->funding_sequences_count = (int) $category->funding_sequences_count);
    }

    /**
     * Underwriting-logic rules of live funding sequences: the one place that
     * says what "used by a funding sequence" means for a delete and the list.
     * A deleted rule or a rule of a deleted sequence does not count; a sequence
     * with several rules for one category counts once (count distinct ids).
     */
    private function fundingSequencesUsing()
    {
        return LenderVisibility::query()
            ->whereHas('fundingSequencing')
            ->distinct();
    }
}
