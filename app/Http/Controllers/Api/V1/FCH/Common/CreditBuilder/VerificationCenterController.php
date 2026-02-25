<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\CreditBuilder\UpdateVerificationStatusRequest;
use App\Services\FCH\Common\CreditBuilder\VerificationCenterService;
use Illuminate\Http\Request;

class VerificationCenterController extends Controller
{
    public function __construct(protected VerificationCenterService $verificationCenterService) {}

    /**
     * Fetch all borrower task verification requests.
     */
    public function fetchAll(Request $request)
    {
        try {
            // Sanitize pagination
            $perPage = $request->input('per_page') ? (int) $request->input('per_page') : 10;
            $perPage = max(1, min($perPage, 100));

            $filters = [
                'per_page' => $perPage,
                'page' => max(1, (int) $request->input('page', 1)),
                'search' => $request->input('search') ? trim((string) $request->input('search')) : null,
                'status' => $request->input('status'),
                'borrower_id' => $request->input('borrower_id'),
                'partner_id' => $request->input('partner_id'),
                'filters' => $request->input('filters', []),
                'sort_field' => $request->input('sort_field', 'request_date'),
                'sort_order' => $request->input('sort_order', 'desc'),
            ];

            return $this->verificationCenterService->fetchAll($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('verification_center.error_fetch_failed'));
        }
    }

    /**
     * Fetch all verification requests for a specific borrower.
     */
    public function fetchBorrowerRequests(Request $request)
    {
        try {
            // Sanitize pagination
            $perPage = $request->input('per_page') ? (int) $request->input('per_page') : 10;
            $perPage = max(1, min($perPage, 100));

            $filters = [
                'per_page' => $perPage,
                'page' => max(1, (int) $request->input('page', 1)),
                'status' => $request->input('status'),
                'search' => $request->input('search') ? trim((string) $request->input('search')) : null,
                'borrower_id' => $request->input('borrower_id'),
                'tier_id' => $request->input('tier_id'),
                'sort_field' => $request->input('sort_field', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
                'filters' => $request->input('filters', []),
            ];

            return $this->verificationCenterService->fetchBorrowerRequests($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('verification_center.error_fetch_failed'));
        }
    }

    /**
     * Update status of verification request.
     */
    public function statusUpdate(UpdateVerificationStatusRequest $request)
    {
        // return ApiResponse::success($request->validated());
        try {
            $this->verificationCenterService->updateStatus($request->validated());

            return ApiResponse::success([], __('verification_center.success_status_updated'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('verification_center.error_status_update_failed'));
        }
    }
}
