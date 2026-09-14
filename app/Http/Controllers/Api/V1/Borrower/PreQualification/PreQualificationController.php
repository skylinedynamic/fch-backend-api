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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Dedoc\Scramble\Attributes\QueryParameter;
use App\Http\Requests\Api\V1\Borrower\PreQualification\PreQualificationRequest as QualificationRequest;
use Illuminate\Http\Request;

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
     * Get specific Pre-Qualification application
     */
    public function show($id)
    {
        try {
            $preQualification = $this->service->getPreQualificationById($id);
            
            if (!$preQualification) {
                return ApiResponse::error(__('borrower.pre_qualification_not_found'), 404);
            }
            
            return ApiResponse::success(
                new PreQualificationResource($preQualification),
                __('borrower.pre_qualification_retrieved_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_retrieval_failed'), 500);
        }
    }


    /**
     * Personal Info
     */
    public function personalInfo(PersonalInfoRequest $request)
    {
        try {
            $record = $this->service->personalInfo($request->validated());
            // Returns the saved record so the caller learns the id of the draft it
            // just created, which the next step has to send back.
            return ApiResponse::success(
                new PreQualificationResource($record),
                __('borrower.pre_qualification_personal_info_successfully')
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::notFound($e->getMessage());
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
        } catch (ModelNotFoundException $e) {
            return ApiResponse::notFound($e->getMessage());
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
        } catch (ModelNotFoundException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.pre_qualification_funding_info_failed'), 500);
        }
    }

    /**
     * Submit Pre-Qualification
     */
    /**
     * Re-run an underwriting review that did not complete.
     */
    public function retryUnderwriting(SubmitPreQualificationRequest $request)
    {
        try {
            return $this->service->retryUnderwriting($request->validated());

        } catch (ModelNotFoundException $e) {
            return ApiResponse::notFound($e->getMessage());

        } catch (\Throwable $e) {
            Log::error('Pre-qualification underwriting retry failed', [
                'user_id' => Auth::id(),
                'pre_qualification_request_id' => $request->validated()['id'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return ApiResponse::serverError(__('borrower.pre_qualification_submission_failed'));
        }
    }

    /**
     * Submit Pre-Qualification
     */
    public function submit(SubmitPreQualificationRequest $request)
    {
        try {
            return $this->service->submit($request->validated());

        } catch (ModelNotFoundException $e) {
            // No submittable draft: wrong id, another borrower's request, or one
            // that was already submitted. That is a caller-side state problem, so
            // it answers 404 with the reason instead of a blank server error.
            return ApiResponse::notFound($e->getMessage());

        } catch (\Throwable $e) {
            // Anything else is a genuine failure and must leave a trace — this
            // previously returned a generic 500 and logged nothing at all.
            Log::error('Pre-qualification submission failed', [
                'user_id' => Auth::id(),
                'pre_qualification_request_id' => $request->validated()['id'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return ApiResponse::serverError(__('borrower.pre_qualification_submission_failed'));
        }
    }
}
