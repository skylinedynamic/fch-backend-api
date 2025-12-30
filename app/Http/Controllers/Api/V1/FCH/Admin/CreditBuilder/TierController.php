<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Tier\StoreTierRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Tier\UpdateTierRequest;
use App\Http\Resources\FCH\CreditBuilder\TierResource;
use App\Http\Resources\FCH\CreditBuilder\TierRelatedApiResource;
use App\Models\CreditBuilder\TierRelatedApi;
use App\Services\FCH\Admin\CreditBuilder\TierService;
use Illuminate\Http\Request;

class TierController extends Controller
{
    protected TierService $tierService;

    public function __construct(TierService $tierService)
    {
        $this->tierService = $tierService;
    }

    /**
     * Get Tiers.
     */
    public function index(Request $request)
    {
        try {
            $filters = [
                'per_page' => $request->input('per_page'),
                'page' => $request->input('page'),
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'sort_field' => $request->input('sort_field'),
                'sort_order' => $request->input('sort_order'),
                'filters' => $request->input('filters'),
            ];

            return $this->tierService->list($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_list_failed'));
        }
    }

    /**
     * Store Tier.
     */
    public function store(StoreTierRequest $request)
    {
        try {
            $tier = $this->tierService->store($request->validated());
            return ApiResponse::success(
                new TierResource($tier),
                __('tier.success_created'),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_create_failed'));
        }
    }

    /**
     * Show Tier Details.
     */
    public function show(int $id)
    {
        try {
            $tier = $this->tierService->findById($id);

            if (!$tier) {
                return ApiResponse::notFound(__('tier.error_not_found'));
            }

            return ApiResponse::success(
                new TierResource($tier),
                __('tier.success_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_retrieve_failed'));
        }
    }

    /**
     * Update Tier.
     */
    public function update(UpdateTierRequest $request, int $id)
    {
        try {
            $tier = $this->tierService->findById($id);

            if (!$tier) {
                return ApiResponse::notFound(__('tier.error_not_found'));
            }

            $updatedTier = $this->tierService->update($tier, $request->validated());

            return ApiResponse::success(
                new TierResource($updatedTier),
                __('tier.success_updated')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_update_failed'));
        }
    }

    /**
     * Delete Multiple Tiers.
     */
    public function multipleDelete(Request $request)
    {
        try {
            $ids = $request->input('ids');

            if (empty($ids) || !is_array($ids)) {
                return ApiResponse::validationError([], __('tier.error_no_ids'));
            }

            $this->tierService->delete($ids);

            return ApiResponse::success(null, __('tier.success_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_delete_failed'));
        }
    }

    /**
     * Get Tier Related APIs.
     */
    public function getTierRelatedApi(int $tier_id)
    {
        try {
            $tierRelatedApis = TierRelatedApi::where('tier_id', $tier_id)->get();

            return ApiResponse::success(
                TierRelatedApiResource::collection($tierRelatedApis),
                __('Tier related APIs retrieved successfully')
            );
        } catch (\Exception $e) {

            return ApiResponse::serverError($e->getMessage());
        }
    }
}
