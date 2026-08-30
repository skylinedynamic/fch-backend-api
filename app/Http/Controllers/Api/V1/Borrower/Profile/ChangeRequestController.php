<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Profile\ChangeRequestService;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreChangeRequest;
use App\Helpers\ApiResponse;

class ChangeRequestController extends Controller
{
    protected $service;

    public function __construct(ChangeRequestService $service)
    {
        $this->service = $service;
    }

    /**
     * Store a new change request.
     *
     * @param StoreChangeRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreChangeRequest $request)
    {
        try {
            $changeRequest = $this->service->submitRequest(auth()->id(), $request->validated());

            return ApiResponse::success(
                $changeRequest,
                __('profile.change_request_submitted')
            );
        }
        catch (\Exception $e) {
            return ApiResponse::error(
                __('profile.change_request_failed'),
                400
            );
        }
    }
}
