<?php

namespace App\Http\Controllers\Api\V1\Borrower\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\CreditBuilder\TaskVerifyRequest;
use App\Http\Resources\Borrower\CreditBuilder\TierResource;
use App\Http\Resources\Borrower\CreditBuilder\TaskVerificationResource;
use App\Services\Borrower\CreditBuilder\CreditBuilderStepGuideService;

class CreditBuilderStepGuideController extends Controller
{
    protected $stepGuideService;

    public function __construct(CreditBuilderStepGuideService $stepGuideService)
    {
        $this->stepGuideService = $stepGuideService;
    }

    /**
     * Get Tier Wise Task List
     */
    public function index()
    {
        $data = $this->stepGuideService->getTierWiseTaskList();

        return ApiResponse::success(
            TierResource::collection($data),
            __('borrower.tier_task_list_retrieved')
        );
    }

    /**
     * Task verification request
     */
    public function taskVerifyRequest(TaskVerifyRequest $request)
    {
        try {
            $verificationRequests = $this->stepGuideService->taskVerifyRequest($request->task_ids);

            return ApiResponse::success(
                TaskVerificationResource::collection($verificationRequests),
                __('borrower.task_verify_request_success')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(
                __('borrower.task_verify_request_failed')
            );
        }
    }
}
