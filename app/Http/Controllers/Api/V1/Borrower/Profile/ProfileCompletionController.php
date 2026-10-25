<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\ChooseOnboardingPathRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\SkipOnboardingStepRequest;
use App\Services\Borrower\Profile\ProfileCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProfileCompletionController extends Controller
{
    protected ProfileCompletionService $service;

    public function __construct(ProfileCompletionService $service)
    {
        $this->service = $service;
    }

    /**
     * Profile Check Completion
     */
    public function check(): JsonResponse
    {
        $result = $this->service->checkCompletion();
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }

    /**
     * Getting Started: the borrower reached Credit Card Stacking.
     */
    public function completeOnboarding(): JsonResponse
    {
        $result = $this->service->completeOnboarding();
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }

    /**
     * Getting Started: the borrower skipped the step they are on.
     */
    public function skipOnboardingStep(SkipOnboardingStepRequest $request): JsonResponse
    {
        $result = $this->service->skipOnboardingStep($request->validated('step'));
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }

    /**
     * Getting Started: the borrower went back to a step they skipped.
     */
    public function resumeOnboardingStep(SkipOnboardingStepRequest $request): JsonResponse
    {
        $result = $this->service->resumeOnboardingStep($request->validated('step'));
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }

    /**
     * Getting Started: with the results ready, the borrower chose what they
     * are looking for (or changed their choice).
     */
    public function chooseOnboardingPath(ChooseOnboardingPathRequest $request): JsonResponse
    {
        $result = $this->service->chooseOnboardingPath($request->validated('path'));
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }
}
