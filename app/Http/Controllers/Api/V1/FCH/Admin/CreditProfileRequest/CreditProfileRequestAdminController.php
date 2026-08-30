<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditProfileRequest;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Borrower\CreditProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

            return ApiResponse::success($requests, 'Credit profile requests retrieved successfully.');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to fetch credit profile requests.');
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
                return ApiResponse::notFound('Credit profile request not found.');
            }

            return ApiResponse::success($creditProfileRequest, 'Credit profile request retrieved successfully.');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to fetch credit profile request details.');
        }
    }
}
