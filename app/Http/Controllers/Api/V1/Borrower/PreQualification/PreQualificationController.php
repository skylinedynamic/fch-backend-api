<?php

namespace App\Http\Controllers\Api\V1\Borrower\PreQualification;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\Borrower\PreQualification\SubmitPreQualificationRequest;
use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Services\Borrower\PreQualification\PreQualificationService;
use Illuminate\Support\Facades\Auth;

class PreQualificationController extends Controller
{
    protected PreQualificationService $service;

    public function __construct(PreQualificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Submit Pre-Qualification
     */
    public function store(SubmitPreQualificationRequest $request)
    {
        try {
            $userId = Auth::id();
            $record = $this->service->submit($userId, $request->validated());
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_submitted_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_submission_failed'), 500);
        }
    }
}
