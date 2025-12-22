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
                'tier_id' => $request->input('tier_id'),
            ];
            $tasks = $this->tierTaskService->list($filters);
            return ApiResponse::success($tasks, __('tier_task.success_list'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier_task.error_list_failed'));
        }
    }
}
