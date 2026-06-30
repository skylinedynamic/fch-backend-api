<?php

namespace App\Http\Controllers\Api\V1\Borrower\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\ActivityLog\StoreBorrowerActivityLogRequest;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Services\Borrower\ActivityLog\BorrowerActivityLogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class BorrowerActivityLogController extends Controller
{
    public function __construct(protected BorrowerActivityLogService $activityLogService) {}

    /**
     * List activity logs (all / filtered by type)
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->all();

            return $this->activityLogService->list($filters);
        } catch (\Exception $e) {
            Log::error('ActivityLog index error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ApiResponse::serverError(__('borrower_activity_log.list_failed'));
        }
    }

    /**
     * Show single activity log detail
     */
    public function show($id)
    {
        try {
            $log = $this->activityLogService->find((int) $id);
            return ApiResponse::success(new ActivityLogResource($log), __('borrower_activity_log.details_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower_activity_log.not_found'));
        }
    }

    public function store(StoreBorrowerActivityLogRequest $request)
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
            $messageKey = $request->log_type === 'email' ? 'borrower_activity_log.email_sent' : 'borrower_activity_log.activity_created';
            
            return ApiResponse::success(
                new ActivityLogResource($log), 
                __($messageKey), 
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            $errorKey = $request->log_type === 'email' ? 'borrower_activity_log.email_send_failed' : 'borrower_activity_log.activity_create_failed';
            return ApiResponse::serverError(__($errorKey));
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
