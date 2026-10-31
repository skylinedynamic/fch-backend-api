<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\FetchActivityLogsRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreActivityLogRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\UpdateActivityLogRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\UpdateActivityLogTaskStatusRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\UpdateActivityLogVisibilityRequest;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    public function __construct(protected ActivityLogService $activityLogService) {}

    /**
     * List activity logs (all / filtered by type)
     */
    public function index(FetchActivityLogsRequest $request)
    {
        try {
            $filters = $request->all();

            return $this->activityLogService->list($filters);
        } catch (\Exception $e) {
            Log::error('ActivityLog index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

    public function store(StoreActivityLogRequest $request)
    {
        try {
            // validated() already includes validated file objects from attachments.*
            // Do NOT re-fetch via $request->file() as it bypasses mimes/size validation
            $data = $request->validated();

            // Merge validated files back in (file inputs are validated but returned
            // as UploadedFile objects when they pass mimes/max checks)
            if ($request->hasFile('attachments')) {
                $data['attachments'] = $request->validated()['attachments'] ?? [];
            }

            $log = $this->activityLogService->store($data);
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

    /**
     * Update an activity log entry (only manual 'activity' type)
     */
    public function update(UpdateActivityLogRequest $request, $id)
    {
        try {
            $data = $request->validated();
            $log = $this->activityLogService->update((int) $id, $data);
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.activity_updated'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            Log::error('ActivityLog update error:', ['id' => $id, 'error' => $e->getMessage()]);
            return ApiResponse::serverError(__('activity_log.activity_update_failed'));
        }
    }

    /**
     * Reclassify an activity's visibility. Host-only, and works on any row
     * (including ones the caller didn't author and system-generated audit
     * entries) — unlike update()/destroy() which stay restricted to your
     * own manual activity.
     */
    public function updateVisibility(UpdateActivityLogVisibilityRequest $request, $id)
    {
        try {
            $log = $this->activityLogService->updateVisibility((int) $id, $request->validated('visibility'));
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.visibility_updated'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            if ($code === 422) return ApiResponse::error($e->getMessage(), 422);
            Log::error('ActivityLog visibility update error:', ['id' => $id, 'error' => $e->getMessage()]);
            return ApiResponse::serverError(__('activity_log.visibility_update_failed'));
        }
    }

    /**
     * Change an assigned task's status from its reminder (creator or assignee).
     */
    public function updateTaskStatus(UpdateActivityLogTaskStatusRequest $request, $id)
    {
        try {
            $log = $this->activityLogService->updateTaskStatus((int) $id, $request->validated('task_status'));
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.task_status_updated'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            if ($code === 422) return ApiResponse::error($e->getMessage(), 422);
            Log::error('ActivityLog task status update error:', ['id' => $id, 'error' => $e->getMessage()]);
            return ApiResponse::serverError(__('activity_log.task_status_update_failed'));
        }
    }

    /**
     * Delete an activity log entry (only manual 'activity' type)
     */
    public function destroy($id)
    {
        try {
            $this->activityLogService->delete((int) $id);
            return ApiResponse::success([], __('activity_log.activity_deleted'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            Log::error('ActivityLog delete error:', ['id' => $id, 'error' => $e->getMessage()]);
            return ApiResponse::serverError(__('activity_log.activity_delete_failed'));
        }
    }

    /**
     * Download activity log attachment
     */
    public function downloadAttachment(Request $request)
    {
        try {
            $logId = $request->input('activity_log_id');
            $pathInput  = $request->input('path');

            if (is_array($pathInput)) {
                $path = $pathInput['path'] ?? (isset($pathInput['id']) ? (string)$pathInput['id'] : null);
            } else {
                $path = $pathInput;
            }

            if (!$logId || !$path) {
                return ApiResponse::error("activity_log_id and path are required");
            }

            return $this->activityLogService->downloadAttachment((int)$logId, (string)$path);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
