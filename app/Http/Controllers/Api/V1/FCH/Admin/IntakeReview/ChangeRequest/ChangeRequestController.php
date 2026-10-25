<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\IntakeReview\ChangeRequest;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\ChangeRequest\FetchChangeRequestsRequest;
use App\Http\Requests\Api\V1\FCH\Common\ChangeRequest\UpdateChangeRequestStatusRequest;
use App\Services\FCH\Common\ChangeRequest\ChangeRequestService;
use Illuminate\Http\JsonResponse;

class ChangeRequestController extends Controller
{
    protected ChangeRequestService $service;

    public function __construct(ChangeRequestService $service)
    {
        $this->service = $service;
    }

    /**
     * List change requests with filters and pagination.
     */
    public function index(FetchChangeRequestsRequest $request): JsonResponse
    {
        try {
            $filters = [
                'per_page' => $request->validated()['per_page'] ?? 15,
                'page' => $request->validated()['page'] ?? 1,
                'search' => $request->validated()['search'] ?? null,
                'status' => $request->validated()['status'] ?? null,
                'partner_id' => $request->validated()['partner_id'] ?? null,
                'borrower_id' => $request->validated()['borrower_id'] ?? null,
                'sort_field' => $request->validated()['sort_field'] ?? null,
                'sort_order' => $request->validated()['sort_order'] ?? null,
            ];

            return $this->service->list($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('profile.change_request_failed'));
        }
    }

    /**
     * Update the status (and optionally note) of a change request.
     */
    public function updateStatus(UpdateChangeRequestStatusRequest $request, int $id): JsonResponse
    {
        try {
            return $this->service->updateStatus($id, $request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('profile.change_request_failed'));
        }
    }
}
