<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\TierTask;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\TierTask\TierTaskService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class TierTaskController extends Controller
{
    protected TierTaskService $tierTaskService;

    public function __construct(TierTaskService $tierTaskService)
    {
        $this->tierTaskService = $tierTaskService;
    }

    /**
     * List all tier tasks.
     */
    public function index(Request $request)
    {

        try {
            $filters = [
                'tier_id'    => $request->input('tier_id'),
                'search'     => $request->input('search'),
                'per_page'   => $request->input('per_page', 10),
                'page'       => $request->input('page', 1),
                'sort_field' => $request->input('sort_field', 'order'),
                'sort_order' => $request->input('sort_order', 'asc'),
            ];

            return $this->tierTaskService->list($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_list_failed'));
        }
    }

    /**
     * Store Tier Task.
     */
    public function store(\App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\StoreTierTaskRequest $request)
    {
        try {
            $tierTask = $this->tierTaskService->store($request->validated());
            return ApiResponse::success(
                new \App\Http\Resources\FCH\CreditBuilder\TierTaskResource($tierTask),
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
                new \App\Http\Resources\FCH\CreditBuilder\TierTaskResource($tierTask),
                __('tier_task.success_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_retrieve_failed'));
        }
    }

    /**
     * Update Tier Task.
     */
    public function update(\App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\UpdateTierTaskRequest $request, int $id)
    {
        try {
            $tierTask = $this->tierTaskService->findById($id);

            if (!$tierTask) {
                return ApiResponse::notFound(__('tier_task.error_not_found'));
            }

            $updatedTierTask = $this->tierTaskService->update($tierTask, $request->validated());

            return ApiResponse::success(
                new \App\Http\Resources\FCH\CreditBuilder\TierTaskResource($updatedTierTask),
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

            $this->tierTaskService->delete($ids);

            return ApiResponse::success(null, __('tier_task.success_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_delete_failed'));
        }
    }
}
