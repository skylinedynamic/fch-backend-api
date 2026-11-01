<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\PartnerFundApplication;

use App\Exceptions\FundingApplicationLockedException;
use App\Exceptions\StaleRecordException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\AttestationAndAuthorizationRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\DisclosuresAndConsentRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\FederalComplianceRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreBusinessInfoRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreFinancialInfoRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreFundingApplicationDocumentRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreFundingRequestRequest;
use App\Services\FCH\Common\Application\SignatureRecorder;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\CoBorrowerValidationRequest;
use App\Http\Resources\FCH\Partner\FundApplication\AttestationAndAuthorizationResource;
use App\Http\Resources\FCH\Partner\FundApplication\BusinessResource;
use App\Http\Resources\FCH\Partner\FundApplication\DisclosuresAndConsentResource;
use App\Http\Resources\FCH\Partner\FundApplication\FederalComplianceResource;
use App\Http\Resources\FCH\Partner\FundApplication\FinancialResource;
use App\Http\Resources\FCH\Partner\FundApplication\FundingApplicationDocumentResource;
use App\Http\Resources\FCH\Partner\FundApplication\FundingRequestResource;
use App\Http\Resources\FCH\Partner\FundApplication\OwnershipResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Services\FCH\Common\PartnerFundApplication\PartnerFundingApplicationService;
use App\Services\FCH\Common\Application\FundingApplicationAuditLogger;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\FCH\Common\Notification\Notifier;
use App\Mail\Borrower\DraftApplicationSentMail;

class PartnerFundingApplicationController extends Controller   
{
    protected PartnerFundingApplicationService $fundingApplicationService;

    public function __construct(PartnerFundingApplicationService $fundingApplicationService)
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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
            $data = $request->validated();
            $data['is_business_info_saved'] = true;
            $businessInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new BusinessResource($businessInfo),
                __('borrower.business_info_updated_successfully')
            );

        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            \Log::error('saveBusinessInfo error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_ownership_info_failed'), 500);
        }
    }


    /**
     * Save Ownership Info.
     */
    public function saveOwnershipInfo(StoreOwnershipInfoRequest $request){
        try {
            $data = $request->validated();
            $data['is_ownership_info_saved'] = true;
            $ownershipInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new OwnershipResource($ownershipInfo),
                __('borrower.ownership_info_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_financial_info_failed'), 500);
        }
    }


    /**
     * Save Financial Info.
     */
    public function saveFinancialInfo(StoreFinancialInfoRequest $request){
        try {
            $data = $request->validated();
            $data['is_financial_info_saved'] = true;
            $financialInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new FinancialResource($financialInfo),
                __('borrower.financial_info_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_funding_request_failed'), 500);
        }
    }

    /**
     * Save Funding Request.
     */
    public function saveFundingRequest(StoreFundingRequestRequest $request){
        try {
            $data = $request->validated();
            $data['is_funding_request_saved'] = true;
            $fundingRequest = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new FundingRequestResource($fundingRequest),
                __('borrower.funding_request_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_disclosures_and_consent_failed'), 500);
        }
    }

    public function saveDisclosuresAndConsent(DisclosuresAndConsentRequest $request){
        try {
            $data = $request->validated();
            $data['is_disclosures_saved'] = true;
            $disclosuresAndConsent = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new DisclosuresAndConsentResource($disclosuresAndConsent),
                __('borrower.disclosures_and_consent_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_federal_compliance_disclosures_failed'), 500);
        }
    }

    public function saveFederalComplianceDisclosures(FederalComplianceRequest $request){
        try {
            $data = $request->validated();
            $data['is_federal_compliance_saved'] = true;
            $federalCompliance = $this->fundingApplicationService->saveFundingApplication($data, FundingApplication::DRAFT);

            $fundingApp = FundingApplication::with('borrower')->find($federalCompliance->funding_application_id ?? $federalCompliance->id);
            if ($fundingApp && $fundingApp->borrower) {
                app(Notifier::class)->send(
                    'borrower.funding_application.draft_ready',
                    $fundingApp->borrower,
                    ['id' => $fundingApp->funding_request_id],
                    mail: (new DraftApplicationSentMail($fundingApp))->to($fundingApp->borrower->email)
                );
            }

            return ApiResponse::success(
                new FederalComplianceResource($federalCompliance),
                __('borrower.federal_compliance_disclosures_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
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

        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_attestation_and_authorization_failed'), 500);
        }
    }

    public function saveAttestationAndAuthorization(AttestationAndAuthorizationRequest $request){
        try {
            $validated = $request->validated();

            // Read the resign flag before saving clears it: it is what separates a
            // first submission from signing again after an edit wiped the previous
            // signature, and the audit trail needs to say which.
            $wasResubmission = false;
            if (!empty($validated['id'])) {
                $priorState = FundingApplication::find($validated['id']);
                $wasResubmission = $priorState ? (bool) $priorState->requires_resign : false;
            }

            // Submitted by a partner user: the certificate says so and names the
            // account, rather than presenting it as the borrower's own login.
            $validated['signature_data'] = SignatureRecorder::record(
                $request,
                SignatureRecorder::METHOD_PARTNER_PORTAL,
                $validated,
                Auth::user()
            );

            // Set old attestation_authorization to true for backward compatibility
            $validated['attestation_authorization'] = true;
            $validated['requires_resign'] = false;

            $attestationAndAuthorization = $this->fundingApplicationService->saveFundingApplication($validated, FundingApplication::PENDING);

            // Log that the application was submitted - or, after an edit cleared
            // the signature, re-signed and re-submitted.
            $borrowerName = !empty($validated['signature_name']) ? $validated['signature_name'] : (Auth::user() ? trim(Auth::user()->first_name . ' ' . Auth::user()->last_name) : 'Borrower');
            app(FundingApplicationAuditLogger::class)->logSubmitted(
                $attestationAndAuthorization,
                $borrowerName,
                $wasResubmission,
                FundingApplicationAuditLogger::ACTOR_PARTNER
            );

            // Generate Certificate and Final PDF via new Document Service
            $documentService = app(FundingApplicationDocumentService::class);
            $documentService->generateAndLockFinalDocuments($attestationAndAuthorization, $validated['signature_data']);

            return ApiResponse::success(
                new AttestationAndAuthorizationResource($attestationAndAuthorization),
                __('borrower.attestation_and_authorization_updated_successfully')
            );
        } catch (StaleRecordException $e) {
            // Saved from an older copy of the application (R2 BUG-033).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
        } catch (ModelNotFoundException $e) {
            // A funding request that is not this borrower's (BUG-082).
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.attestation_and_authorization_update_failed') . ': ' . $e->getMessage(), 500);
        }
    }


    public function getDocuments(Request $request, $funding_application_id)
    {
        try {
            if (!$funding_application_id) {
                return ApiResponse::error(__('borrower.funding_application_id_required'), 400);
            }

            $app = FundingApplication::visibleTo(Auth::user())->where('id', $funding_application_id)->first();

            if (!$app) {
                $app = FundingApplication::visibleTo(Auth::user())->where('funding_request_id', $funding_application_id)
                    ->latest()
                    ->first();
            }

            if (!$app) {
                return ApiResponse::error(__('borrower.application_not_found'), 404);
            }

            $borrowerId = $app->borrower_id;
            $appId = $app->id;

            // Ensure borrower uploaded documents are synced to the application
            // Removed for Partner: Do not auto-sync borrower documents so the step doesn't get a false tick
            // $this->fundingApplicationService->createFundingApplicationDocumentDir($appId);

            $documents = FundingApplicationDocument::query()
                ->where('funding_application_id', $appId)
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound(__('borrower.document_not_found'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.document_delete_failed'), 500);
        }
    }

    public function downloadDocument($id)
    {
        try {
            // Reachable exactly as far as its application's borrower is.
            $document = FundingApplicationDocument::whereHas('fundingApplication', fn ($applications) => $applications->visibleTo(Auth::user()))
                ->findOrFail($id);

            $urlPath = $document->path ?: $document->url;

            if (!$urlPath) {
                return ApiResponse::notFound('File path not found.');
            }

            $relativePath = $urlPath;
            if (str_contains($relativePath, '/storage/')) {
                $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
            }

            $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($relativePath);

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
    /**
     * Co-Borrower Validation
     */
    public function coBorrowerValidation(CoBorrowerValidationRequest $request)
    {
        return ApiResponse::success([], __('borrower_validation.co_borrower_validation_success'));
    }
}
