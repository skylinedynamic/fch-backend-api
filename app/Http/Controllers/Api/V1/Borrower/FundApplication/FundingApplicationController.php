<?php

namespace App\Http\Controllers\Api\V1\Borrower\FundApplication;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\FundApplication\BusinessResource;
use App\Http\Resources\Borrower\FundApplication\DisclosuresAndConsentResource;
use App\Http\Resources\Borrower\FundApplication\FederalComplianceResource;
use App\Http\Resources\Borrower\FundApplication\AttestationAndAuthorizationResource;
use App\Http\Resources\Borrower\FundApplication\FinancialResource;
use App\Http\Resources\Borrower\FundApplication\FundingRequestResource;
use App\Http\Resources\Borrower\FundApplication\OwnershipResource;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Http\Request;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreBusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFinancialInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFundingRequestRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\DisclosuresAndConsentRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\FederalComplianceRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\AttestationAndAuthorizationRequest;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Http\Resources\Borrower\FundApplication\FundingApplicationDocumentResource;
use Illuminate\Support\Facades\Auth;

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
            $attestationAndAuthorization = $this->fundingApplicationService->saveFundingApplication($request->validated(),FundingApplication::PENDING);

            return ApiResponse::success(
                new AttestationAndAuthorizationResource($attestationAndAuthorization),
                __('borrower.attestation_and_authorization_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.attestation_and_authorization_update_failed'), 500);
        }
    }


    public function getDocuments(Request $request)
    {
        try {
            
            $fundingApplicationId = $request->query('funding_application_id');
            $borrowerId = Auth::user()->entity_id;

            if (!$fundingApplicationId) {
                return ApiResponse::error(__('borrower.funding_application_id_required'), 400);
            }

            $documents = FundingApplicationDocument::query()
                ->where('funding_application_id', $fundingApplicationId)
                ->where('borrower_id', $borrowerId)
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('borrower_doc_type_id');

            // Map the grouped collection using the resource
            $payload = $documents->map(function ($group) {
                return FundingApplicationDocumentResource::collection($group);
            });

            return ApiResponse::success($payload, __('borrower.documents_fetched_successfully'));

        } catch (\Exception $e) {
            return ApiResponse::error('borrower.documents_fetched_failed', 500);
        }
    }
}
