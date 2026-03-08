<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreActivityLogRequest;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogController extends Controller
{
    public function __construct(protected ActivityLogService $activityLogService) {}

    /**
     * List activity logs (all / filtered by type)
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->all();

            return $this->activityLogService->list($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('activity_log.list_failed'));
        }
    }

    /**
     * Show single activity log detail
     */
    public function show($id)
    {
        try {
            $log = $this->activityLogService->find((int) $id);
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.details_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('activity_log.not_found'));
        }
    }

    /**
     * Store a new Activity Log (Activity or Email)
     */
    public function store(StoreActivityLogRequest $request)
    {
        try {
            $log = $this->activityLogService->store($request->validated());
            $messageKey = $request->log_type === 'email' ? 'activity_log.email_sent' : 'activity_log.activity_created';
            
            return ApiResponse::success(
                new ActivityLogResource($log), 
                __($messageKey), 
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            $errorKey = $request->log_type === 'email' ? 'activity_log.email_send_failed' : 'activity_log.activity_create_failed';
            return ApiResponse::serverError(__($errorKey));
        }
    }
}
