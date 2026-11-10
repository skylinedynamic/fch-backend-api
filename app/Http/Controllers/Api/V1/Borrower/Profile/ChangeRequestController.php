<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Profile\ChangeRequestGuard;
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
     * The borrower's fields with a change request waiting for review (R2 BUG-039),
     * so the profile can say so before another is filed. Field names only.
     */
    public function pending(ChangeRequestGuard $guard)
    {
        $borrower = auth()->user()?->entity;

        return ApiResponse::success($borrower ? $guard->pendingFields($borrower) : [], __('profile.change_request_pending_list'));
    }

    /**
     * The borrower cancels one of its own requests still waiting for review;
     * it is force deleted. Another's is not found; a reviewed one is refused.
     */
    public function cancel(int $id)
    {
        $cancelled = $this->service->cancel(auth()->id(), $id);

        if ($cancelled === null) {
            return ApiResponse::error(__('profile.change_request_not_found'), 404);
        }

        if ($cancelled === false) {
            return ApiResponse::error(__('profile.change_request_already_processed'), 422);
        }

        return ApiResponse::success(null, __('profile.change_request_cancelled'));
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
