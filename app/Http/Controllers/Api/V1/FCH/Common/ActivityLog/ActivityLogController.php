<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreActivityRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreEmailRequest;
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
            $filters = [
                'log_name'    => $request->input('log_name'),
                'borrower_id' => $request->input('borrower_id'),
                'partner_id'  => $request->input('partner_id'),
                'search'      => $request->input('search'),
                'sort_field'  => $request->input('sort_field', 'id'),
                'sort_order'  => $request->input('sort_order', 'desc'),
                'per_page'    => $request->input('per_page', 10),
                'page'        => $request->input('page', 1),
                'filters'     => $request->input('filters', []),
            ];

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
     * Store New Activity
     */
    public function storeActivity(StoreActivityRequest $request)
    {
        try {
            $log = $this->activityLogService->createActivity($request->validated());
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.activity_created'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('activity_log.activity_create_failed'));
        }
    }

    /**
     * Store New Email (log and send)
     */
    public function storeEmail(StoreEmailRequest $request)
    {
        try {
            $log = $this->activityLogService->createEmail($request->validated());
            return ApiResponse::success(new ActivityLogResource($log), __('activity_log.email_sent'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('activity_log.email_send_failed'));
        }
    }
}
