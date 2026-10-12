<?php

namespace App\Http\Controllers\Api\V1\Borrower\FundApplication;

use App\Exceptions\FundingApplicationLockedException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\FundApplication\AttestationAndAuthorizationRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\SignatureImageRequest;
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
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use App\Services\FCH\Common\Application\FundingApplicationAuditLogger;
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
            $data = $request->validated();
            $data['is_business_info_saved'] = true;
            $businessInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new BusinessResource($businessInfo),
                __('borrower.business_info_updated_successfully')
            );

        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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
            $data = $request->validated();
            $data['is_ownership_info_saved'] = true;
            $ownershipInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new OwnershipResource($ownershipInfo),
                __('borrower.ownership_info_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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
            $data = $request->validated();
            $data['is_financial_info_saved'] = true;
            $financialInfo = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new FinancialResource($financialInfo),
                __('borrower.financial_info_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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
            $data = $request->validated();
            $data['is_funding_request_saved'] = true;
            $fundingRequest = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new FundingRequestResource($fundingRequest),
                __('borrower.funding_request_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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
            $data = $request->validated();
            $data['is_disclosures_saved'] = true;
            $disclosuresAndConsent = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new DisclosuresAndConsentResource($disclosuresAndConsent),
                __('borrower.disclosures_and_consent_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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
            $data = $request->validated();
            $data['is_federal_compliance_saved'] = true;
            $federalCompliance = $this->fundingApplicationService->saveFundingApplication($data);

            return ApiResponse::success(
                new FederalComplianceResource($federalCompliance),
                __('borrower.federal_compliance_disclosures_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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

    /**
     * The file the borrower picked in the file manager to sign with.
     *
     * The request has already refused anything that is not their own PNG/JPG
     * within the size limit; what comes back is that image, ready to place in
     * the signature box.
     */
    public function signatureImage(SignatureImageRequest $request)
    {
        try {
            $contents = \Illuminate\Support\Facades\Storage::disk('public')->get($request->storagePath());
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

            // Every browser shows PNG and JPEG. Anything else (WEBP, AVIF, GIF,
            // BMP ...) is turned into a PNG here, so it displays whatever the
            // borrower's browser happens to support. A type this server cannot
            // read either (HEIC, for one) is passed through as it is and left
            // to the browser.
            if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
                $size = @getimagesizefromstring($contents);
                // Decoding is skipped for very large pictures - it would need
                // more memory than a signature is worth.
                $decoded = $size && ($size[0] * $size[1]) <= 16000000 ? @imagecreatefromstring($contents) : false;

                if ($decoded !== false) {
                    imagesavealpha($decoded, true);
                    ob_start();
                    imagepng($decoded);
                    $contents = ob_get_clean();
                    $mime = 'image/png';
                    imagedestroy($decoded);
                }
            }

            return ApiResponse::success([
                'image' => 'data:' . $mime . ';base64,' . base64_encode($contents),
                'file_name' => basename($request->storagePath()),
                'size_kb' => (int) ceil(strlen($contents) / 1024),
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower_validation.signature_file_not_found'), 422, [
                'signature_image' => __('borrower_validation.signature_file_not_found'),
            ]);
        }
    }

    public function saveAttestationAndAuthorization(AttestationAndAuthorizationRequest $request){
        try {
            $validated = $request->validated();

            // Read the application as it stands before this save rewrites it. Two
            // things are taken from it for the audit trail:
            //
            //  - whether this is a first submission or a re-submission. It is a
            //    re-submission whenever the application has been signed before -
            //    a re-sign is owed after an edit, or a signature is still on file
            //    and the borrower is simply signing again.
            //  - the signature being replaced, so the log can show the old one
            //    beside the new one.
            $priorState = FundingApplication::where('borrower_id', Auth::user()->entity_id)
                ->when(
                    ! empty($validated['id']),
                    fn ($query) => $query->where('id', $validated['id']),
                    fn ($query) => $query->where('funding_request_id', $validated['funding_request_id'] ?? 0)->latest('id')
                )
                ->first();

            $previousSignature = null;
            if ($priorState) {
                $previousSignature = ! empty($priorState->signature_data)
                    ? $priorState->signature_data
                    : ($priorState->requires_resign ? $priorState->previousSignature() : null);
            }
            $wasResubmission = $priorState
                ? ((bool) $priorState->requires_resign || ! empty($priorState->signature_data))
                : false;

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

            // Typed name or e-signature - one or the other, never both. An
            // e-signature carries no typed name, so the account holder's name is
            // recorded as the printed name beside the image.
            $isEsign = ($validated['signature_type'] ?? 'typed') === 'esign';
            if ($isEsign) {
                $validated['signature_name'] = trim(Auth::user()->first_name . ' ' . Auth::user()->last_name)
                    ?: trim((string) Auth::user()->name)
                    ?: trim((string) ($priorState?->full_name ?? ''))
                    ?: 'Borrower';
            }
            $signatureImage = $isEsign ? ($validated['signature_image'] ?? null) : null;
            // The date the borrower put in the signature block. `signed_at`
            // below stays the server's own record of when it was submitted.
            $signatureDate = $isEsign ? ($validated['signature_date'] ?? null) : null;
            // Not columns; they live inside signature_data below.
            unset($validated['signature_image'], $validated['signature_type'], $validated['signature_date']);

            $validated['signature_data'] = [
                'signature_name' => $validated['signature_name'] ?? null,
                'signature_type' => $isEsign ? 'esign' : 'typed',
                'signature_image' => $signatureImage,
                'signature_date' => $signatureDate,
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
            $validated['requires_resign'] = false;

            // Signing again while a signature is still on file replaces it. Keep
            // the one being replaced, so "Previous Signature" stays accurate
            // (after an edit the guard has already kept it).
            if ($priorState && ! empty($priorState->signature_data)) {
                $validated['previous_signature_data'] = $priorState->signature_data;
            }

            $attestationAndAuthorization = $this->fundingApplicationService->saveFundingApplication($validated, FundingApplication::PENDING);

            // Log that the borrower submitted - or, having signed before,
            // re-signed and re-submitted - the application, with the signature
            // that was replaced and the new one.
            $borrowerName = !empty($validated['signature_name']) ? $validated['signature_name'] : (Auth::user() ? trim(Auth::user()->first_name . ' ' . Auth::user()->last_name) : 'Borrower');
            app(FundingApplicationAuditLogger::class)->logSubmitted(
                $attestationAndAuthorization,
                $borrowerName,
                $wasResubmission,
                FundingApplicationAuditLogger::ACTOR_BORROWER,
                $previousSignature,
                $validated['signature_data']
            );

            // Generate Certificate and Final PDF via new Document Service
            $documentService = app(FundingApplicationDocumentService::class);
            $documentService->generateAndLockFinalDocuments($attestationAndAuthorization, $validated['signature_data']);

            return ApiResponse::success(
                new AttestationAndAuthorizationResource($attestationAndAuthorization),
                __('borrower.attestation_and_authorization_updated_successfully')
            );
        } catch (FundingApplicationLockedException $e) {
            // The application has moved past the borrower's and partner's reach.
            // 423, not 500: the request itself was fine, this application just
            // isn't theirs to change any more.
            return ApiResponse::error($e->getMessage(), 423);
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

            $app = FundingApplication::where('id', $funding_application_id)
                ->where('borrower_id', $borrowerId)
                ->first();

            if (!$app) {
                $app = FundingApplication::where('funding_request_id', $funding_application_id)
                    ->where('borrower_id', $borrowerId)
                    ->latest()
                    ->first();
            }

            $appId = $app ? $app->id : $funding_application_id;

            // Ensure borrower uploaded documents are synced to the application
            $this->fundingApplicationService->createFundingApplicationDocumentDir($appId);

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
}
