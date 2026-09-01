<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditProfileRequest;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Borrower\CreditProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CreditProfileRequestAdminController extends Controller
{
    /**
     * List credit profile requests with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 15);
            $search = $request->input('search');

            $query = CreditProfileRequest::with('borrower')->orderBy('created_at', 'desc');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhereHas('borrower', function ($qb) use ($search) {
                          $qb->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%")
                             ->orWhere('business_name', 'like', "%{$search}%");
                      });
                });
            }

            $requests = $query->paginate($perPage);

            return ApiResponse::success($requests, __('credit_profile_request.retrieved_successfully'));
        } catch (\Exception $e) {
            Log::error(__('credit_profile_request.log_retrieval_failed', ['error' => $e->getMessage()]));
            return ApiResponse::serverError(__('credit_profile_request.retrieval_failed'));
        }
    }

    /**
     * Show details of a specific credit profile request.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $creditProfileRequest = CreditProfileRequest::with('borrower')->find($id);

            if (!$creditProfileRequest) {
                return ApiResponse::notFound(__('credit_profile_request.not_found'));
            }

            return ApiResponse::success($creditProfileRequest, __('credit_profile_request.details_retrieved_successfully'));
        } catch (\Exception $e) {
            Log::error(__('credit_profile_request.log_details_retrieval_failed', ['error' => $e->getMessage()]));
            return ApiResponse::serverError(__('credit_profile_request.details_retrieval_failed'));
        }
    }
}
