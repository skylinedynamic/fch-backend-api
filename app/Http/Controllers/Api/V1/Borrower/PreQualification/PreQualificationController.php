<?php

namespace App\Http\Controllers\Api\V1\Borrower\PreQualification;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
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
     * Submit Pre-Qualification
     */
    public function storeOrUpdate(SubmitPreQualificationRequest $request)
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
