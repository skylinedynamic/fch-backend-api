<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Application;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\Application\AssignFundingApplicationLenderRequest;
use App\Http\Requests\Api\V1\FCH\Common\Application\FetchRequestApplicationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Application\UpdatePreQualificationStatusRequest;
use App\Http\Requests\Api\V1\FCH\Common\Application\UpdateFundingApplicationStatusRequest;
use App\Services\FCH\Common\Application\RequestApplicationService;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestApplicationController extends Controller
{
    protected RequestApplicationService $requestApplicationService;
    protected FundingApplicationDocumentService $documentService;

    public function __construct(
        RequestApplicationService $requestApplicationService,
        FundingApplicationDocumentService $documentService
    ) {
        $this->requestApplicationService = $requestApplicationService;
        $this->documentService = $documentService;
    }

    /**
     * Get Pre-Qualification Applications List
     */
    public function getPreQualification(FetchRequestApplicationRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();
            
            // Standardize generic list filters
            $filters = [
                'per_page'      => $input['per_page'] ?? null,
                'page'          => $input['page'] ?? 1,
                'search'        => $input['search'] ?? null,
                'filters'       => $input['filters'] ?? [],
                'sort_field'    => $input['sort_field'] ?? null,
                'sort_order'    => $input['sort_order'] ?? null,
                'status'        => $input['status'] ?? null,
                'start_date'    => $input['start_date'] ?? null,
                'end_date'      => $input['end_date'] ?? null,
                'funding_type'  => $input['funding_type'] ?? null,
            ];

            return $this->requestApplicationService->getPreQualificationList($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplication(FetchRequestApplicationRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();

            $filters = [
                'per_page'      => $input['per_page'] ?? null,
                'page'          => $input['page'] ?? 1,
                'search'        => $input['search'] ?? null,
                'filters'       => $input['filters'] ?? [],
                'sort_field'    => $input['sort_field'] ?? null,
                'sort_order'    => $input['sort_order'] ?? null,
                'status'        => $input['status'] ?? null,
                'borrower_id'   => $input['borrower_id'] ?? null,
                // The Date Range filter, in the viewer's days (the DATE column
                // shows created_at in their timezone).
                'start_date'    => $input['start_date'] ?? null,
                'end_date'      => $input['end_date'] ?? null,
                'timezone'      => $request->header('X-Timezone') ?? config('app.timezone'),
            ];

            return $this->requestApplicationService->getFundingApplicationList($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id): JsonResponse
    {
        try {
            return $this->requestApplicationService->getPreQualificationById($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }

    /**
     * Run AI Pre-Qualification Underwriting Analysis By ID
     */
    public function runAiPreQualification($id): JsonResponse
    {
        try {
            return $this->requestApplicationService->runAiPreQualification($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage() ?: __('Failed to run pre-qualification AI evaluation.'));
        }
    }


    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id): JsonResponse
    {
        try {
            return $this->requestApplicationService->getFundingApplicationById($id);
        } catch (\Exception $e) {
            
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }

    /**
     * Get Funding Application Audit History (Paginated)
     */
    public function getAuditHistory($id, Request $request): JsonResponse
    {
        try {
            $filters = [
                'per_page'      => $request->input('per_page', 10),
                'page'          => $request->input('page', 1),
                'search'        => $request->input('search'),
                'sort_by'       => $request->input('sort_by'),
                'sort_dir'      => $request->input('sort_dir'),
                'timezone'      => $request->header('X-Timezone') ?? config('app.timezone'),
            ];
            return $this->requestApplicationService->getAuditHistory($id, $filters);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Audit History Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ApiResponse::serverError(__('application.error_retrieving_audit_history'));
        }
    }

    /**
     * Download Funding Application PDF
     */
    public function downloadApplication(Request $request, $id)
    {
        try {
            // The password travels in the body, never the URL (BUG-094): a
            // query string is written to server and proxy logs.
            if ($request->query->has('password')) {
                return ApiResponse::error(__('application.download_password_in_body'), 422);
            }
            return $this->documentService->downloadApplicationPdf($id, $request->input('password'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_downloading_funding_application'));
        }
    }

    /**
     * Download Signature PDF
     */
    public function downloadSignature(Request $request, $id)
    {
        try {
            // The password travels in the body, never the URL (BUG-094): a
            // query string is written to server and proxy logs.
            if ($request->query->has('password')) {
                return ApiResponse::error(__('application.download_password_in_body'), 422);
            }
            return $this->documentService->downloadSignaturePdf($id, $request->input('password'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_downloading_signature'));
        }
    }

    /**
     * Download Both PDFs as ZIP
     */
    public function downloadZip(Request $request, $id)
    {
        try {
            // The password travels in the body, never the URL (BUG-094): a
            // query string is written to server and proxy logs.
            if ($request->query->has('password')) {
                return ApiResponse::error(__('application.download_password_in_body'), 422);
            }
            return $this->documentService->downloadZip($id, $request->input('password'));
        } catch (\Exception $e) {
            return ApiResponse::serverError('An error occurred while generating the ZIP file.');
        }
    }

    /**
     * Download File from Audit History
     */
    public function downloadFromHistory($historyId)
    {
        try {
            return $this->documentService->downloadFromHistory($historyId);
        } catch (\Exception $e) {
            return ApiResponse::serverError('An error occurred while downloading the document: ' . $e->getMessage());
        }
    }

    /**
     * Update Pre-Qualification Application Status
     */
    public function preQualificationStatusUpdate(UpdatePreQualificationStatusRequest $request): JsonResponse
    {
        try {
            return $this->requestApplicationService->preQualificationStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_updating_status'));
        }
    }

    /**
     * Update Funding Application Status
     */
    public function fundingApplicationStatusUpdate(UpdateFundingApplicationStatusRequest $request): JsonResponse
    {
        try {
            return $this->requestApplicationService->fundingApplicationStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_updating_status'));
        }
    }


    /**
     * Assign a lender to a funding application (host's team only).
     */
    public function assignFundingApplicationLender(AssignFundingApplicationLenderRequest $request, int $id): JsonResponse
    {
        try {
            return $this->requestApplicationService->assignFundingApplicationLender($id, $request->validated('lender_ids'));
        } catch (\Exception $e) {
            Log::error('Assign funding application lender failed', ['funding_application_id' => $id, 'error' => $e->getMessage()]);

            return ApiResponse::serverError(__('application.error_assigning_lender'));
        }
    }

    public function getPreQualificationCardSummary()
    {
        try {
            return $this->requestApplicationService->getPreQualificationCardSummary();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }

    public function getFundingApplicationCardSummary()
    {
        try {
            return $this->requestApplicationService->getFundingApplicationCardSummary();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Funding Application List Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }


}
