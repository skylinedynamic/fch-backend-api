<?php

namespace App\Http\Controllers\Api\V1\Borrower\PreQualification;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\Borrower\PreQualification\BusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\PreQualification\FundingInfoRequest;
use App\Http\Requests\Api\V1\Borrower\PreQualification\PersonalInfoRequest;
use App\Http\Requests\Api\V1\Borrower\PreQualification\SubmitPreQualificationRequest;
use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Services\Borrower\PreQualification\PreQualificationService;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Dedoc\Scramble\Attributes\QueryParameter;
use App\Http\Requests\Api\V1\Borrower\PreQualification\PreQualificationRequest as QualificationRequest;

class PreQualificationController extends Controller
{
    use PaginatesApi;
    protected PreQualificationService $service;

    public function __construct(PreQualificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Pre-Qualification
     */

    public function index()
    {
        try {
            return $this->service->prequalificationRequest();
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_list_failed'), 500);
        }
    }


    /**
     * Personal Info
     */
    public function personalInfo(PersonalInfoRequest $request)
    {
        try {
            $record = $this->service->personalInfo($request->validated());
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_personal_info_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_personal_info_failed'), 500);
        }
    }

    /**
     * Business Info
     */
    public function businessInfo(BusinessInfoRequest $request)
    {
        try {
            $record = $this->service->businessInfo($request->validated());
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_business_info_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_business_info_failed'), 500);
        }
    }

    /**
     * Funding Info
     */

    public function fundingInfo(FundingInfoRequest $request)
    {
        try {
            $record = $this->service->fundingInfo($request->validated());
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_funding_info_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_funding_info_failed'), 500);
        }
    }

    /**
     * Submit Pre-Qualification
     */
    public function submit(SubmitPreQualificationRequest $request)
    {
        try {
            $record = $this->service->submit($request->validated());
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_submitted_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_submission_failed'), 500);
        }
    }
}
