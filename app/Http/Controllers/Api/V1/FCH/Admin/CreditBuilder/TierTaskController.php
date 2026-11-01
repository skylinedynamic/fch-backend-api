<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\StoreTierTaskRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\UpdateTierTaskRequest;
use App\Http\Resources\FCH\CreditBuilder\TierTaskResource;
use App\Services\FCH\Admin\CreditBuilder\TierTaskService;
use Illuminate\Http\Request;

class TierTaskController extends Controller
{
    protected TierTaskService $tierTaskService;

    public function __construct(TierTaskService $tierTaskService)
    {
        $this->tierTaskService = $tierTaskService;
    }

    /**
     * Get Tier Tasks.
     */
    public function index(Request $request)
    {
        try {
            $filters = [
                'per_page' => $request->input('per_page'),
                'page' => $request->input('page'),
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'tier_id' => $request->input('tier_id'),
                'sort_field' => $request->input('sort_field'),
                'sort_order' => $request->input('sort_order'),
                'filters' => $request->input('filters'),
            ];

            return $this->tierTaskService->list($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_list_failed'));
        }
    }

    /**
     * Store Tier Task.
     */
    public function store(StoreTierTaskRequest $request)
    {
        try {
            $tierTask = $this->tierTaskService->store($request->validated());
            return ApiResponse::success(
                new TierTaskResource($tierTask),
                __('tier_task.success_created'),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_create_failed'));
        }
    }

    /**
     * Show Tier Task Details.
     */
    public function show(int $id)
    {
        try {
            $tierTask = $this->tierTaskService->findById($id);

            if (!$tierTask) {
                return ApiResponse::notFound(__('tier_task.error_not_found'));
            }

            return ApiResponse::success(
                new TierTaskResource($tierTask),
                __('tier_task.success_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_retrieve_failed'));
        }
    }

    /**
     * Update Tier Task.
     */
    public function update(UpdateTierTaskRequest $request, int $id)
    {
        try {
            $tierTask = $this->tierTaskService->findById($id);

            if (!$tierTask) {
                return ApiResponse::notFound(__('tier_task.error_not_found'));
            }

            $updatedTierTask = $this->tierTaskService->update($tierTask, $request->validated());

            return ApiResponse::success(
                new TierTaskResource($updatedTierTask),
                __('tier_task.success_updated')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_update_failed'));
        }
    }

    /**
     * Delete Multiple Tier Tasks.
     */
    public function multipleDelete(Request $request)
    {
        try {
            $ids = $request->input('ids');

            if (empty($ids) || !is_array($ids)) {
                return ApiResponse::validationError([], __('tier_task.error_no_ids'));
            }

            // A task clients already use stays, and so does the rest of this delete.
            $inUse = $this->tierTaskService->inUse($ids);
            if ($inUse) {
                return ApiResponse::error(__('tier_task.error_in_use'), 422, ['in_use' => $inUse]);
            }

            $this->tierTaskService->delete($ids);

            return ApiResponse::success(null, __('tier_task.success_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_delete_failed'));
        }
    }
}
