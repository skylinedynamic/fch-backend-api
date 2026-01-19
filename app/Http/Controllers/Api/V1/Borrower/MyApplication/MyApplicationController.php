<?php

namespace App\Http\Controllers\Api\V1\Borrower\MyApplication;

use App\Http\Controllers\Controller;
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
}
