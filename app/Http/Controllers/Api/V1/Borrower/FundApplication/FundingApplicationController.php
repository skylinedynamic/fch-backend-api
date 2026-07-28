<?php

namespace App\Http\Controllers\Api\V1\Borrower\FundApplication;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\FundApplication\AttestationAndAuthorizationRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\DisclosuresAndConsentRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\FederalComplianceRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreBusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFinancialInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFundingApplicationDocumentRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFundingRequestRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreOwnershipInfoRequest;
use App\Http\Resources\Borrower\FundApplication\AttestationAndAuthorizationResource;
use App\Http\Resources\Borrower\FundApplication\BusinessResource;
use App\Http\Resources\Borrower\FundApplication\DisclosuresAndConsentResource;
use App\Http\Resources\Borrower\FundApplication\FederalComplianceResource;
use App\Http\Resources\Borrower\FundApplication\FinancialResource;
use App\Http\Resources\Borrower\FundApplication\FundingApplicationDocumentResource;
use App\Http\Resources\Borrower\FundApplication\FundingRequestResource;
use App\Http\Resources\Borrower\FundApplication\OwnershipResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FundingApplicationController extends Controller   
{
    protected FundingApplicationService $fundingApplicationService;

    public function __construct(FundingApplicationService $fundingApplicationService)
    {
        $this->fundingApplicationService = $fundingApplicationService;
    }

    /**
     * Get Business Info.
     */
    public function getBusinessInfo()
    {
        try {
            $businessInfo = $this->fundingApplicationService->getFundingInfo();
            
            if (!$businessInfo) {
                return ApiResponse::error(__('borrower.business_info_not_found'), 404);
            }

            return ApiResponse::success(
                new BusinessResource($businessInfo),
                __('borrower.fetch_business_info')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_business_info_failed'), 500);
        }
    }


    /**
     * Save Business Info.
     */
    public function saveBusinessInfo(StoreBusinessInfoRequest $request)
    {
        try {
            $businessInfo = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new BusinessResource($businessInfo),
                __('borrower.business_info_updated_successfully')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.business_info_update_failed'), 500);
        }
    }


    /**
     * Get Ownership Info.
     */
    public function getOwnershipInfo(){
        try {   
            $borrowerInfo = $this->fundingApplicationService->getFundingInfo();
            
            if (!$borrowerInfo) {
                return ApiResponse::error(__('borrower.ownership_info_not_found'), 404);
            }

            return ApiResponse::success(
                new OwnershipResource($borrowerInfo),
                __('borrower.fetch_ownership_info')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_ownership_info_failed'), 500);
        }
    }


    /**
     * Save Ownership Info.
     */
    public function saveOwnershipInfo(StoreOwnershipInfoRequest $request){
        try {
            $ownershipInfo = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new OwnershipResource($ownershipInfo),
                __('borrower.ownership_info_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }


    /**
     * Get Financial Info.
     */
    public function getFinancialInfo(){
         try {   
            $financialInfo = $this->fundingApplicationService->getFundingInfo();
            
            if (!$financialInfo) {
                return ApiResponse::error(__('borrower.financial_info_not_found'), 404);
            }

            return ApiResponse::success(
                new FinancialResource($financialInfo),
                __('borrower.fetch_financial_info')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_financial_info_failed'), 500);
        }
    }


    /**
     * Save Financial Info.
     */
    public function saveFinancialInfo(StoreFinancialInfoRequest $request){
        try {
            $financialInfo = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new FinancialResource($financialInfo),
                __('borrower.financial_info_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.financial_info_update_failed'), 500);
        }
    }

    /**
     * Get Funding Request.
     */
    public function getFundingRequest(){
         try {   
            $fundingRequest = $this->fundingApplicationService->getFundingInfo();
            
            if (!$fundingRequest) {
                return ApiResponse::error(__('borrower.funding_request_not_found'), 404);
            }

            return ApiResponse::success(
                new FundingRequestResource($fundingRequest),
                __('borrower.fetch_funding_request')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_funding_request_failed'), 500);
        }
    }

    /**
     * Save Funding Request.
     */
    public function saveFundingRequest(StoreFundingRequestRequest $request){
        try {
            $fundingRequest = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new FundingRequestResource($fundingRequest),
                __('borrower.funding_request_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.funding_request_update_failed'), 500);
        }
    }


    public function getDisclosuresAndConsent(){
         try {   
            $disclosuresAndConsent = $this->fundingApplicationService->getFundingInfo();
            
            if (!$disclosuresAndConsent) {
                return ApiResponse::error(__('borrower.disclosures_and_consent_not_found'), 404);
            }

            return ApiResponse::success(
                new DisclosuresAndConsentResource($disclosuresAndConsent),
                __('borrower.fetch_disclosures_and_consent')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_disclosures_and_consent_failed'), 500);
        }
    }

    public function saveDisclosuresAndConsent(DisclosuresAndConsentRequest $request){
        try {
            $disclosuresAndConsent = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new DisclosuresAndConsentResource($disclosuresAndConsent),
                __('borrower.disclosures_and_consent_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.disclosures_and_consent_update_failed'), 500);
        }
    }


    public function getFederalComplianceDisclosures(){
        try {   
            $federalCompliance = $this->fundingApplicationService->getFundingInfo();
            
            if (!$federalCompliance) {
                return ApiResponse::error(__('borrower.federal_compliance_not_found'), 404);
            }

            return ApiResponse::success(
                new FederalComplianceResource($federalCompliance),
                __('borrower.fetch_federal_compliance_disclosures')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_federal_compliance_disclosures_failed'), 500);
        }
    }

    public function saveFederalComplianceDisclosures(FederalComplianceRequest $request){
        try {
            $federalCompliance = $this->fundingApplicationService->saveFundingApplication($request->validated());

            return ApiResponse::success(
                new FederalComplianceResource($federalCompliance),
                __('borrower.federal_compliance_disclosures_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.federal_compliance_disclosures_update_failed'), 500);
        }
    }

    public function getAttestationAndAuthorization(){
        try {   
            $attestationAndAuthorization = $this->fundingApplicationService->getFundingInfo();
            
            if (!$attestationAndAuthorization) {
                return ApiResponse::error(__('borrower.attestation_and_authorization_not_found'), 404);
            }

            return ApiResponse::success(
                new AttestationAndAuthorizationResource($attestationAndAuthorization),
                __('borrower.fetch_attestation_and_authorization')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_attestation_and_authorization_failed'), 500);
        }
    }

    public function saveAttestationAndAuthorization(AttestationAndAuthorizationRequest $request){
        try {
            $validated = $request->validated();
            
            // Extract agent info
            $agent = $request->userAgent();
            $os = 'Unknown';
            if (preg_match('/windows|win32|macintosh|mac os x|linux|android|iphone|ipad/i', $agent, $matches)) {
                $os = $matches[0];
            }
            $browser = 'Unknown';
            if (preg_match('/chrome|firefox|safari|edge|opera|msie|trident/i', $agent, $matches)) {
                $browser = $matches[0];
            }
            
            // Extract Browser Version
            $browserVersion = 'Unknown';
            if ($browser !== 'Unknown') {
                if (preg_match('/version[\/ ]([\d.]+)/i', $agent, $matches)) {
                    $browserVersion = $matches[1];
                } else if (preg_match('/' . preg_quote($browser, '/') . '[\/ ]([\d.]+)/i', $agent, $matches)) {
                    $browserVersion = $matches[1];
                }
            }
            
            // Extract Device Type
            $deviceType = 'Desktop';
            if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $agent)) {
                $deviceType = 'Tablet';
            } else if (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer|Dolfin|Dolphin|Skyfire|Zune/i', $agent)) {
                $deviceType = 'Mobile';
            }

            $validated['signature_data'] = [
                'signature_name' => $validated['signature_name'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $agent,
                'browser_name' => $browser,
                'browser_version' => $browserVersion,
                'operating_system' => $os,
                'device_type' => $deviceType,
                'timezone' => $validated['timezone'] ?? null,
                'esign_consent' => $validated['esign_consent'] ?? false,
                'terms_consent' => $validated['terms_consent'] ?? false,
                'credit_consent' => $validated['credit_consent'] ?? false,
                'legal_binding_consent' => $validated['legal_binding_consent'] ?? false,
                'terms_version' => $validated['terms_version'] ?? '1.0',
                'privacy_version' => $validated['privacy_version'] ?? '1.0',
                'credit_auth_version' => $validated['credit_auth_version'] ?? '1.0',
                'application_version' => $validated['application_version'] ?? '1.0',
                'consents_data' => isset($validated['consents_data']) ? json_decode($validated['consents_data'], true) : null,
                'signed_at' => now()->toDateTimeString(),
            ];

            // Set old attestation_authorization to true for backward compatibility
            $validated['attestation_authorization'] = true;

            $attestationAndAuthorization = $this->fundingApplicationService->saveFundingApplication($validated, FundingApplication::PENDING);

            // Log that the borrower submitted the application
            $activityLogService = app(ActivityLogService::class);
            $borrowerName = !empty($validated['signature_name']) ? $validated['signature_name'] : (Auth::user() ? trim(Auth::user()->first_name . ' ' . Auth::user()->last_name) : 'Borrower');
            $activityLogService->logModelEvent(
                $attestationAndAuthorization,
                ActivityLog::TYPE_ACTIVITY,
                ['visible_to_borrower' => '1'],
                'Funding Application Submitted',
                "Funding Application submitted by {$borrowerName}."
            );

            // Generate Certificate and Final PDF via new Document Service
            $documentService = app(FundingApplicationDocumentService::class);
            $documentService->generateAndLockFinalDocuments($attestationAndAuthorization, $validated['signature_data']);

            return ApiResponse::success(
                new AttestationAndAuthorizationResource($attestationAndAuthorization),
                __('borrower.attestation_and_authorization_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.attestation_and_authorization_update_failed') . ': ' . $e->getMessage(), 500);
        }
    }


    public function getDocuments(Request $request, $funding_application_id)
    {
        try {
            $borrowerId = Auth::user()->entity_id;

            if (!$funding_application_id) {
                return ApiResponse::error(__('borrower.funding_application_id_required'), 400);
            }

            $documents = FundingApplicationDocument::query()
                ->where('funding_application_id', $funding_application_id)
                ->where('borrower_id', $borrowerId)
                ->orderByDesc('created_at')
                ->get();

            return ApiResponse::success(FundingApplicationDocumentResource::collection($documents), __('borrower.documents_fetched_successfully'));

        } catch (\Exception $e) {
            return ApiResponse::error('borrower.documents_fetched_failed', 500);
        }
    }

    public function uploadDocument(StoreFundingApplicationDocumentRequest $request)
    {
        try {
            return $this->fundingApplicationService->uploadDocument($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function deleteDocument($id)
    {
        try {
            $this->fundingApplicationService->deleteDocument($id);

            return ApiResponse::success(
                null,
                __('borrower.document_deleted_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.document_delete_failed'), 500);
        }
    }

    public function downloadDocument($id)
    {
        try {
            $document = FundingApplicationDocument::findOrFail($id);
            
            // Check if borrower owns this document
            if ($document->borrower_id != Auth::user()->entity_id) {
                return ApiResponse::error('Unauthorized access to this document.', 403);
            }

            $urlPath = $document->path ?: $document->url;

            if (!$urlPath) {
                return ApiResponse::notFound('File path not found.');
            }

            $relativePath = $urlPath;
            if (str_contains($relativePath, '/storage/')) {
                $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
            }

            $filePath = storage_path('app/public/' . $relativePath);

            if (!file_exists($filePath)) {
                return ApiResponse::notFound('File not found on server.');
            }

            $mimeType = mime_content_type($filePath);
            $fileName = $document->original_name ?: basename($filePath);

            return response()->download($filePath, $fileName, [
                'Content-Type' => $mimeType ?: 'application/octet-stream',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound('Document not found.');
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to download document: ' . $e->getMessage(), 500);
        }
    }
}
