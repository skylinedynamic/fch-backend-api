<?php

namespace App\Http\Controllers\Api\V1\Borrower\MyApplication;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\MyApplication\CoBorrowerValidationRequest;
use App\Http\Requests\Api\V1\Borrower\MyApplication\GetMyApplicationRequest;
use App\Services\Borrower\Application\MyApplicationService;
use Illuminate\Http\JsonResponse;

class MyApplicationController extends Controller
{
    protected $myApplicationService;

    public function __construct(MyApplicationService $myApplicationService)
    {
        $this->myApplicationService = $myApplicationService;
    }

    /**
     * Pre-qualification Applications
     */
    public function preQualification(GetMyApplicationRequest $request): JsonResponse
    {
        return $this->myApplicationService->preQualificationList($request->validated());
    }


    /**
     * Funding Applications
     */
    public function fundingApplication(GetMyApplicationRequest $request): JsonResponse
    {
        return $this->myApplicationService->fundingApplicationList($request->validated());
    }

    /**
     * Co-Borrower Validation
     */
    public function coBorrowerValidation(CoBorrowerValidationRequest $request): JsonResponse
    {
        return ApiResponse::success([], __('borrower_validation.co_borrower_validation_success'));
    }
}
