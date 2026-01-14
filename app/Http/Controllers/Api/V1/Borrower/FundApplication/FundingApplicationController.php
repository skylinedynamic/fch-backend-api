<?php

namespace App\Http\Controllers\Api\V1\Borrower\FundApplication;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\FundApplication\BusinessResource;
use App\Http\Resources\Borrower\FundApplication\DisclosuresAndConsentResource;
use App\Http\Resources\Borrower\FundApplication\FinancialResource;
use App\Http\Resources\Borrower\FundApplication\FundingRequestResource;
use App\Http\Resources\Borrower\FundApplication\OwnershipResource;
use App\Services\Borrower\FundApplication\FundingApplicationService;

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
            $businessInfo = $this->fundingApplicationService->getBorrowerInfo();
            
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
    public function saveBusinessInfo()
    {
      
    }


    /**
     * Get Ownership Info.
     */
    public function getOwnershipInfo(){
        try {   
            $borrowerInfo = $this->fundingApplicationService->getBorrowerInfo();
            
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
     * Save Business Info.
     */
    public function saveOwnershipInfo(){

    }


    /**
     * Get Financial Info.
     */
    public function getFinancialInfo(){
         try {   
            $financialInfo = $this->fundingApplicationService->getBorrowerInfo();
            
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
     * Get Funding Request.
     */
    public function getFundingRequest(){
         try {   
            $fundingRequest = $this->fundingApplicationService->getBorrowerInfo();
            
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


    public function getDisclosuresAndConsent(){
         try {   
            $disclosuresAndConsent = $this->fundingApplicationService->getBorrowerInfo();
            
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


    public function saveFederalComplianceDisclosures(){
        try {   
            $disclosuresAndConsent = $this->fundingApplicationService->getBorrowerInfo();
            
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

}
