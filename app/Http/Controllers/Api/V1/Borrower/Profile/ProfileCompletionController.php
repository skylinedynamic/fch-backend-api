<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
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
        $user = Auth::user();
        $result = $this->service->checkCompletion($user);
        return ApiResponse::success($result, __('borrower.fetch_profile_completion'));
    }
}