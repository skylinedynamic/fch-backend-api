<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\UpdateTaskStatusRequest;
use App\Services\FCH\Admin\CreditBuilder\TaskVerificationService;

class TaskVerificationController extends Controller
{
    protected TaskVerificationService $service;

    public function __construct(TaskVerificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Update status of multiple tasks for a borrower.
     */
    public function updateStatus(UpdateTaskStatusRequest $request)
    {
        try {
            $results = $this->service->updateStatus($request->validated());

            return ApiResponse::success($results, __('verification_center.tasks_updated_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage() ?: __('verification_center.error_status_update_failed'));
        }
    }
}
