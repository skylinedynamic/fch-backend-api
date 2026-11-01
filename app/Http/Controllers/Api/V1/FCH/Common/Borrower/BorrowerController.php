<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Borrower;

use App\Exceptions\StaleRecordException;
use App\Services\FCH\Common\Notification\Notifier;
use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Rules\FicoScore;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Borrower\BorrowerDocument;
use App\Services\FCH\Common\Tier\TierService;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Services\Borrower\Profile\CreditAuthorizationConsentService;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Http\Resources\FCH\Borrower\BorrowerDetailsResource;
use App\Http\Resources\FCH\Borrower\BorrowerProfileResource;
use App\Http\Resources\FCH\Borrower\BorrowerCreditProfileResource;
use App\Services\FCH\Common\Borrower\BorrowerDocumentService;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\FetchBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateDocumentStatusRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UploadBorrowerDocumentRequest;
use App\Http\Resources\FCH\Borrower\BorrowerPersonalDetailsResource;
use App\Http\Resources\FCH\Borrower\BorrowerBusinessFinancialsResource;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerBusinessFinancialsRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\ArchiveBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\RevealBorrowerFieldRequest;

class BorrowerController extends Controller
{
    protected BorrowerService $borrowerService;
    public function __construct(BorrowerService $borrowerService)
    {
        $this->borrowerService = $borrowerService;
    }

    /**
     * Get Borrowers List.
     */
    public function index(FetchBorrowerRequest $request)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            return $this->borrowerService->list($request->validated());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Borrower index error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('borrower.list_failed'));
        }
    }

    /**
     * Store Borrower.
     */
    public function store(StoreBorrowerRequest $request)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            $data = $request->validated();

            $result = $this->borrowerService->store($data);

            $borrower = $result['borrower'];
            $user = $result['user'] ?? null;
            $plainPassword = $result['plain_password'] ?? null;

            return ApiResponse::success(
                new BorrowerDetailsResource($borrower),
                __('borrower.created'),
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }

    /**
     * Update Borrower Business Financials.
     */
    public function BusinessFinancials(UpdateBorrowerBusinessFinancialsRequest $request, string $id)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);
            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $data = $request->validated();
            $updatedBorrower = $this->borrowerService->updateBusinessFinancials($borrower, $data);

            return ApiResponse::success(
                new BorrowerBusinessFinancialsResource($updatedBorrower),
                __('borrower.updated')
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Borrower updateBusinessFinancials error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('borrower.update_failed'));
        }
    }

    /**
     * Update Borrower.
     */
    public function update(UpdateBorrowerRequest $request, string $id)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);
            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $data = $request->validated();
            $updatedBorrower = $this->borrowerService->update($borrower, $data);

            return ApiResponse::success(
                new BorrowerDetailsResource($updatedBorrower),
                __('borrower.updated')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            // e.g. the new email is already taken — the form needs to show why.
            throw $e;
        } catch (StaleRecordException $e) {
            // Changed by someone else since this form loaded (R2 BUG-065).
            return ApiResponse::error($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.update_failed'));
        }
    }

    /**
     * One of the client's masked numbers in full (BUG-014): `field` names it.
     */
    public function reveal(RevealBorrowerFieldRequest $request, string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $field = $request->validated('field');

            return ApiResponse::success(
                ['field' => $field, 'value' => $this->borrowerService->revealField($borrower, $field)],
                __('borrower.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    /**
     * Get Borrower Details.
     */
    public function show(string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            return ApiResponse::success(
                new BorrowerDetailsResource($borrower),
                __('borrower.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    /**
     * Delete Borrowers.
     */
    public function multipleDelete(Request $request)
    {
        $this->checkWhiteLabelConfiguration();
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:borrowers,id',
        ]);

        return $this->borrowerService->delete($request->ids);
    }

    /**
     * Archive borrowers: take them out of the working list without deleting them.
     *
     * Presentation only - `status` is untouched, so an archived borrower keeps
     * whatever access and billing they had.
     */
    public function archive(ArchiveBorrowerRequest $request)
    {
        $this->checkWhiteLabelConfiguration();

        return $this->borrowerService->archive($request->validated()['ids']);
    }

    /** Restore archived borrowers to the working list. */
    public function unarchive(ArchiveBorrowerRequest $request)
    {
        $this->checkWhiteLabelConfiguration();

        return $this->borrowerService->unarchive($request->validated()['ids']);
    }

    /**
     * Check if multiple borrowers are deletable (for modal)
     */
    public function checkDeletable(Request $request)
    {
        try {
            $results = $this->borrowerService->checkDeletable($request->ids);
            return ApiResponse::success($results, __('borrower.retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Get All Users
     */
    public function getAllUsers(Request $request)
    {
        try {
            $users = $this->borrowerService->getAllUsers();

            return ApiResponse::success(
                $users,
                __('borrower.users_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.users_fetch_failed'));
        }
    }

    /**
     * Get Document Types
     */
    public function documentTypes()
    {
        try {
            $documentService = new BorrowerDocumentService();
            return $documentService->documentTypes();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.document_types_fetch_failed'));
        }
    }


    /**
     * Get Tiers List
     * TODO: When tiers table is created, modify to fetch from database
     */
    public function getTiers(TierService $tierService)
    {
        try {
            $tiers = $tierService->list();
            return ApiResponse::success($tiers, __('borrower.tiers_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.tiers_fetch_failed'));
        }
    }

    /**
     * Send Account Created Mail
     */
    public function sendAccountCreatedMail($user, $businessInfo, string $plainPassword): void
    {
        $data = [
            'subject' => __('borrower.account_created_subject', ['company_name' => $businessInfo->company_name]),
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'company_name' => $businessInfo->company_name,
                'company_logo' => $businessInfo->company_logo,
                'email' => $businessInfo->email,
                'phone_number' => $businessInfo->phone_number,
                'address_line_1' => $businessInfo->address_line_1,
            ],
        ];

        try {
            app(Notifier::class)->send('borrower.account.created', $user, ['name' => $user->name],
                mail: (new BorrowerAccountCreatedMail($data, $user->partner_id))->to($user->email));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('📧 BorrowerAccountCreatedMail failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Profile States Count
     */
    public function profileStatsCount(int $borrowerId)
    {
        try {
            $data = $this->borrowerService->getBorrowerProfileStats($borrowerId);

            return ApiResponse::success($data, __('borrower.stats_retrieved') ?? 'Stats retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound(__('borrower.not_found'));
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('borrower.stats_retrieved_failed') ?? 'Failed to retrieve stats', 500);
        }
    }

    /**
     * Fetch Borrower Documents List
     */
    public function fetchBorrowerDocuments($borrowerId)
    {
        try {
            $documents = $this->borrowerService->getBorrowerDocuments((int) $borrowerId);
            return ApiResponse::success($documents, __('borrower.documents_fetched_successfully'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound(__('borrower.not_found'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.documents_fetched_failed'));
        }
    }

    /**
     * Download Borrower Document.
     */
    public function downloadDocument(int $documentId)
    {

        try {
            // Note: Route parameter is named {borrowerId}, but for download it likely refers to the Document ID.
            $document = BorrowerDocument::visibleTo(auth()->user())->where('id', $documentId)->first();
            if (!$document) {
                return ApiResponse::notFound(__('borrower.document_not_found'));
            }

            // Prefer file_path, fallback to document column if file_path is empty
            $urlPath = $document->file_path ?: $document->document;

            // If still empty
            if (!$urlPath) {
                return ApiResponse::notFound(__('borrower.file_path_not_found'));
            }

            // Extract relative path from full URL if needed
            // e.g., "http://127.0.0.1:8000/storage/borrowers/documents/aa.png" -> "borrowers/documents/aa.png"
            $relativePath = $urlPath;
            if (str_contains($relativePath, '/storage/')) {
                $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
            }

            // Get the full path to the file
            $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($relativePath);

            // Check if file exists
            if (!file_exists($filePath)) {
                return ApiResponse::notFound(__('borrower.file_not_found_on_server'));
            }

            // Get MIME type
            $mimeType = mime_content_type($filePath);

            // Determine filename (use file_name if available, else basename)
            $fileName = $document->file_name ?: basename($relativePath);

            // Return file download with proper headers
            return response()->download(
                $filePath,
                $fileName,
                [
                    'Content-Type' => $mimeType,
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                ]
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.download_failed'));
        }
    }

    /**
     * Upload Borrower Document
     */
    public function uploadDocument(UploadBorrowerDocumentRequest $request)
    {
        try {
            $documentService = new BorrowerDocumentService();
            return $documentService->createBorrowerDocuments($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Update Document Status
     */
    public function updateDocumentStatus(UpdateDocumentStatusRequest $request, int $id)
    {
        try {
            $documentService = new BorrowerDocumentService();
            return $documentService->updateStatus($id, $request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error(__('borrower.document_status_update_failed'));
        }
    }

    /**
     * Delete Borrower Document
     */
    public function deleteDocument($id)
    {
        try {
            $documentService = new BorrowerDocumentService();
            $deleted = $documentService->removeById((int) $id);

            if (!$deleted) {
                return ApiResponse::notFound(__('borrower.document_not_found'));
            }

            return ApiResponse::success(null, __('borrower.document_deleted_successfully'));
        } catch (\Throwable $e) {
            return ApiResponse::error(__('borrower.document_delete_failed'));
        }
    }
    /**
     * Get Borrower Profile.
     */
    public function getBorrowerProfile(int $borrowerId)
    {
        try {
            $borrower = $this->borrowerService->getDetails($borrowerId);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            return ApiResponse::success(
                new BorrowerProfileResource($borrower),
                __('borrower.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    /**
     * Get Borrower Personal Details.
     */
    public function getCreditProfile(int $borrowerId)
    {
        try {
            $borrower = $this->borrowerService->getDetails($borrowerId);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $creditProfile = $borrower->creditProfile;

            if (!$creditProfile) {
                return ApiResponse::success(null, __('borrower.retrieved_details'));
            }

            $data = (new BorrowerCreditProfileResource($creditProfile))->toArray(request());
            // Whether this user may upload, update or remove it - the host's team.
            $data['can_manage'] = \App\Http\Middleware\EnsureHostPartner::allows(auth()->user());

            // Load PDF if available
            if ($creditProfile->pdf_url) {
                $pdfContent = null;
                if (str_contains($creditProfile->pdf_url, '/storage/')) {
                    $relativePath = substr($creditProfile->pdf_url, strpos($creditProfile->pdf_url, '/storage/') + strlen('/storage/'));
                    $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($relativePath);
                    if (file_exists($filePath)) {
                        $pdfContent = file_get_contents($filePath);
                    }
                } else {
                    $httpResponse = Http::timeout(30)->get($creditProfile->pdf_url);
                    if ($httpResponse->successful()) {
                        $pdfContent = $httpResponse->body();
                    }
                }
                if ($pdfContent) {
                    $data['pdf'] = base64_encode($pdfContent);
                }
            }

            return ApiResponse::success($data, __('borrower.retrieved_details'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    public function getPersonalDetails(string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            return ApiResponse::success(
                new BorrowerPersonalDetailsResource($borrower),
                __('borrower.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    /**
     * Get Borrower Business Financials.
     */
    public function getBusinessFinancials(string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int) $id);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            return ApiResponse::success(
                new BorrowerBusinessFinancialsResource($borrower),
                __('borrower.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.fetch_failed'));
        }
    }

    /**
     * Parse Credit Profile PDF
     */
    public function parseCreditProfile(Request $request)
    {
        // Validated outside the try below, whose catch-all would turn a
        // validation failure into a 500.
        $request->validate([
            'borrower_id' => 'required|integer|exists:borrowers,id',
            'pdf' => 'required|file|mimes:pdf|max:15360', // max 15MB
        ]);

        try {
            // A parse that works is not logged. A consumer report is FCRA
            // material, so a failure logs what went wrong, never what it says.
            $borrowerId = (int) $request->input('borrower_id');
            $borrower = Borrower::visibleTo(auth()->user())->find($borrowerId);
            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $pdfFile = $request->file('pdf');

            // Extract text from PDF using Smalot PDF Parser. A file it cannot
            // open gets a plain message, never the parser's own (BUG-144).
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdfFile->getPathname());
                $text = $pdf->getText();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('parseCreditProfile unreadable PDF', ['message' => $e->getMessage()]);
                return ApiResponse::error(__('borrower.credit_profile_unreadable_pdf'), 422);
            }

            // Validate that we got some text
            if (empty(trim($text))) {
                return ApiResponse::error('The uploaded PDF does not contain selectable text (it might be scanned). Please upload a digital PDF report.');
            }

            // Determine parser mode from app_configurations
            // Try partner-specific config first, then fall back to host (partner_id=null or host partner) config
            $partnerId = $borrower->partner_id;
            $parserMode = \App\Models\Common\AppConfiguration::get('credit_profile_parser_mode', null, 'credit_profile', $partnerId);
            if ($parserMode === null) {
                // Fallback: try without partner (host-level config)
                $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
                $parserMode = \App\Models\Common\AppConfiguration::get('credit_profile_parser_mode', 'manual', 'credit_profile', $hostPartner?->id);
                $partnerId = $hostPartner?->id;
            }
            if ($parserMode === null) {
                $parserMode = 'manual';
            }

            $parserService = $this->getParserService($parserMode, $partnerId);
            $extractedData = $parserService->parse($text);

            // A readable PDF the parser finds nothing in is not a credit
            // report: refused before anything of it is stored (BUG-144).
            if (!$this->looksLikeCreditReport($extractedData)) {
                return ApiResponse::error(__('borrower.credit_profile_not_a_report'), 422);
            }

            // Store PDF in public storage under borrowers/{borrower_id}
            $fileName = 'credit_report_' . time() . '.pdf';
            $path = $pdfFile->storeAs("borrowers/{$borrowerId}", $fileName, 'public');

            // Find an appropriate document type or use null
            $docType = \App\Models\Borrower\BorrowerDocType::where('name', 'Others')->orWhere('name', 'Miscellaneous Documents')->first();

            // Create BorrowerDocument
            $borrowerDoc = \App\Models\Borrower\BorrowerDocument::create([
                'borrower_id' => $borrowerId,
                'file_name' => $pdfFile->getClientOriginalName(),
                'file_path' => $fileName,
                'document_type_id' => $docType?->id,
                'file_size' => round(filesize($pdfFile->getPathname()) / 1024, 2) . ' KB',
                'status' => \App\Models\Borrower\BorrowerDocument::STATUS_UPLOADED,
                'created_by' => \Illuminate\Support\Facades\Auth::id(),
            ]);
            // The credit profile's report, kept out of the client's Documents.
            $borrowerDoc->forceFill(['is_credit_report' => true])->save();

            // Read PDF content as base64 for immediate frontend display
            $pdfContent = null;
            $storedPath = "borrowers/{$borrowerId}/{$fileName}";
            $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($storedPath);
            if (file_exists($filePath)) {
                $pdfContent = base64_encode(file_get_contents($filePath));
            }

            return ApiResponse::success([
                'extracted_data' => $extractedData,
                'pdf_url' => asset("storage/borrowers/{$borrowerId}/{$fileName}"),
                'document_id' => $borrowerDoc->id,
                'pdf' => $pdfContent,
                'mime' => 'application/pdf'
            ], 'Credit profile PDF parsed successfully.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('parseCreditProfile error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ApiResponse::serverError('An error occurred while parsing the credit report: ' . $e->getMessage());
        }
    }

    /**
     * Save credit profile details to database
     */
    public function saveCreditProfile(Request $request)
    {
        // Validated outside the try below, whose catch-all would turn a
        // validation failure into a 500.
        $request->validate([
            'borrower_id' => 'required|integer|exists:borrowers,id',
            'metrics' => 'nullable|array',
            // Stored on the borrower too, where it decides lender matching.
            'metrics.fico_score' => ['nullable', new FicoScore()],
            'personal_info' => 'nullable|array',
            'addresses' => 'nullable|array',
            'trade_accounts' => 'nullable|array',
            'inquiries' => 'nullable|array',
            'pdf_url' => 'nullable|string',
            'document_id' => 'nullable|integer|exists:borrower_documents,id',
            // Optional: ties this pull to the consent that authorised it.
            'isoftpull_transaction_id' => 'nullable|string|max:191',
        ]);

        try {
            $borrowerId = (int) $request->input('borrower_id');
            $borrower = \App\Models\Borrower\Borrower::visibleTo(auth()->user())->find($borrowerId);
            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.not_found'));
            }

            $metrics = $request->input('metrics') ?? [];
            $personalInfo = $request->input('personal_info') ?? [];
            // The profile goes out with the SSN masked (BUG-145): a mask sent
            // back unchanged keeps the number on file.
            if (is_string($personalInfo['ssn'] ?? null) && str_contains($personalInfo['ssn'], '*')) {
                $personalInfo['ssn'] = $borrower->creditProfile?->personal_info['ssn'] ?? null;
            }
            $addresses = $request->input('addresses') ?? [];
            $tradeAccounts = $request->input('trade_accounts') ?? [];
            $inquiries = $request->input('inquiries') ?? [];
            $pdfUrl = $request->input('pdf_url');
            $documentId = $request->input('document_id');

            // One transaction for the borrower's own columns and the profile
            // lines: a failure part-way must not leave the borrower claiming a
            // credit profile whose lines were never written.
            \Illuminate\Support\Facades\DB::beginTransaction();

            // Update primary columns for searchability/filtering
            $borrower->fico_score = isset($metrics['fico_score']) ? (int)$metrics['fico_score'] : null;
            $borrower->paydex_score = isset($metrics['paydex_score']) ? (int)$metrics['paydex_score'] : null;
            $borrower->credit_profile_updated = true;
            $borrower->save();

            // Set is_refresh = true in the user table for this borrower
            if ($borrower->user) {
                $borrower->user->update(['is_refresh' => true]);
            }

            // 2. Create or update the main BorrowerCreditProfile
            $allAccounts = $metrics['all_accounts_analysis'] ?? [];
            $openAccounts = $metrics['open_accounts_analysis'] ?? [];

            $profileData = [
                'fico_score' => isset($metrics['fico_score']) ? (int)$metrics['fico_score'] : null,
                'paydex_score' => isset($metrics['paydex_score']) ? (int)$metrics['paydex_score'] : null,
                'experian_intelliscore' => isset($metrics['experian_intelliscore']) ? (int)$metrics['experian_intelliscore'] : null,
                'equifax_score' => isset($metrics['equifax_score']) ? (int)$metrics['equifax_score'] : null,
                'active_tradelines' => isset($metrics['active_tradelines']) ? (int)$metrics['active_tradelines'] : null,
                'total_credit_limit' => isset($metrics['total_credit_limit']) ? (float)$metrics['total_credit_limit'] : null,
                'credit_utilization' => isset($metrics['credit_utilization']) ? (float)$metrics['credit_utilization'] : null,
                'recent_inquiries' => isset($metrics['recent_inquiries']) ? (int)$metrics['recent_inquiries'] : null,
                'delinquent_accounts' => isset($metrics['delinquent_accounts']) ? (int)$metrics['delinquent_accounts'] : null,
                'public_records' => isset($metrics['public_records']) ? (int)$metrics['public_records'] : null,
                'collections' => isset($metrics['collections']) ? (int)$metrics['collections'] : null,
                'delinquencies_30_60_90' => $metrics['delinquencies_30_60_90'] ?? null,
                'summary' => $metrics['summary'] ?? null,
                'pdf_url' => $pdfUrl,
                'document_id' => $documentId,
                'personal_info' => $personalInfo,

                // All accounts analysis
                'all_accounts_total' => isset($allAccounts['total_accounts']) ? (int)$allAccounts['total_accounts'] : null,
                'all_accounts_open' => isset($allAccounts['open_accounts']) ? (int)$allAccounts['open_accounts'] : null,
                'all_accounts_closed' => isset($allAccounts['closed_accounts']) ? (int)$allAccounts['closed_accounts'] : null,
                'all_accounts_newest_open_date' => $allAccounts['newest_open_date'] ?? null,
                'all_accounts_oldest_open_date' => $allAccounts['oldest_open_date'] ?? null,
                'all_accounts_analysis' => $allAccounts,

                // Open accounts analysis
                'open_accounts_monthly_payments' => isset($openAccounts['monthly_payments']) ? (float)$openAccounts['monthly_payments'] : null,
                'open_accounts_balance' => isset($openAccounts['balance']) ? (float)$openAccounts['balance'] : (isset($openAccounts['open_accounts_balance']) ? (float)$openAccounts['open_accounts_balance'] : null),
                'open_accounts_credit_limit' => isset($openAccounts['credit_limit']) ? (float)$openAccounts['credit_limit'] : null,
                'open_accounts_available_credit' => isset($openAccounts['available_credit']) ? (float)$openAccounts['available_credit'] : null,
                'open_accounts_utilization_ratio' => isset($openAccounts['utilization_ratio']) ? (float)$openAccounts['utilization_ratio'] : null,
                'open_accounts_analysis' => $openAccounts,
            ];

            $creditProfile = $borrower->creditProfile;
            if ($creditProfile) {
                $creditProfile->update($profileData);
                // 1. Delete existing child entries
                $creditProfile->addresses()->delete();
                $creditProfile->trades()->delete();
                $creditProfile->inquiries()->delete();
            } else {
                $profileData['borrower_id'] = $borrowerId;
                $creditProfile = \App\Models\Borrower\BorrowerCreditProfile::create($profileData);
            }

            // 3. Create Addresses
            foreach ($addresses as $addr) {
                \App\Models\Borrower\BorrowerCreditAddress::create([
                    'borrower_credit_profile_id' => $creditProfile->id,
                    'type' => $addr['type'] ?? 'CURRENT',
                    'street' => $addr['street'] ?? '',
                    'city' => $addr['city'] ?? '',
                    'state' => $addr['state'] ?? '',
                    'zip' => $addr['zip'] ?? '',
                    'date_reported' => $addr['date_reported'] ?? null,
                ]);
            }

            // 4. Create Trade Accounts
            foreach ($tradeAccounts as $trade) {
                \App\Models\Borrower\BorrowerCreditTrade::create([
                    'borrower_credit_profile_id' => $creditProfile->id,
                    'company' => $trade['company'] ?? '',
                    'date_reported' => $trade['date_reported'] ?? null,
                    'status' => $trade['status'] ?? 'OPEN',
                    'rating' => $trade['rating'] ?? null,
                    'account_number' => $trade['account_number'] ?? null,
                    'account_type' => $trade['account_type'] ?? null,
                    'portfolio_type' => $trade['portfolio_type'] ?? null,
                    'remarks' => $trade['remarks'] ?? null,
                    'payment_history_start_date' => $trade['payment_history_start_date'] ?? null,
                    'payment_frequency' => $trade['payment_frequency'] ?? null,
                    'responsibility' => $trade['responsibility'] ?? null,
                    'opened_date' => $trade['opened_date'] ?? null,
                    'terms' => $trade['terms'] ?? null,
                    'last_payment_date' => $trade['last_payment_date'] ?? null,
                    
                    'balance' => isset($trade['balance']) ? (float)$trade['balance'] : null,
                    'high_credit' => isset($trade['high_credit']) ? (float)$trade['high_credit'] : null,
                    'limit' => isset($trade['limit']) ? (float)$trade['limit'] : null,
                    'monthly_payment' => isset($trade['monthly_payment']) ? (float)$trade['monthly_payment'] : null,
                    'actual_payment' => isset($trade['actual_payment']) ? (float)$trade['actual_payment'] : null,
                    'past_due' => isset($trade['past_due']) ? (float)$trade['past_due'] : null,
                    
                    'months_reviewed' => isset($trade['months_reviewed']) ? (int)$trade['months_reviewed'] : null,
                    'delinquencies_30_60_90' => $trade['delinquencies'] ?? ($trade['delinquencies_30_60_90'] ?? null),
                    'max_delinquency_date' => $trade['max_delinquency_date'] ?? null,
                ]);
            }

            // 5. Create Inquiries
            foreach ($inquiries as $inq) {
                \App\Models\Borrower\BorrowerCreditInquiry::create([
                    'borrower_credit_profile_id' => $creditProfile->id,
                    'date' => $inq['date'] ?? null,
                    'company' => $inq['company'] ?? '',
                    'industry' => $inq['industry'] ?? null,
                ]);
            }

            \Illuminate\Support\Facades\DB::commit();

            // The pull this borrower authorised has now happened, so tie it to
            // the consent behind it: `pulled_at`, plus the iSoftPull transaction
            // id when one was supplied. Only ever adds - `consented_at` stays as
            // it was, because when the client agreed is the fact being proved.
            //
            // After the commit and in its own try/catch on purpose: a missing
            // consent must be visible in the log, not silently roll back a credit
            // profile that was saved correctly.
            try {
                $consentService = app(CreditAuthorizationConsentService::class);
                $consent = $consentService->latestUnattached($borrowerId);

                if ($consent) {
                    $consentService->attachPull($consent, $request->input('isoftpull_transaction_id'));
                } else {
                    // Worth knowing about: a profile was saved with no unused
                    // authorisation on file to attribute it to.
                    Log::warning(__('credit_profile_request.log_no_consent_to_attach', [
                        'borrower_id' => $borrowerId,
                    ]));
                }
            } catch (\Throwable $consentException) {
                Log::error(__('credit_profile_request.log_attach_consent_failed', [
                    'borrower_id' => $borrowerId,
                    'error' => $consentException->getMessage(),
                ]));
            }

            $this->notifyBorrowerCreditProfileUpdated($borrower);

            return ApiResponse::success($creditProfile, 'Credit profile saved successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // A refused request is a 422 with the field errors, not a 500.
            throw $e;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('saveCreditProfile error', [
                'message' => $e->getMessage()
            ]);
            return ApiResponse::serverError('Failed to save credit profile: ' . $e->getMessage());
        }
    }

    /**
     * Remove a client's credit profile data outright (host partner only - see
     * routes/common.php). Its credit authorisation consents are kept.
     */
    public function deleteCreditProfile(int $borrowerId)
    {
        $borrower = Borrower::find($borrowerId);

        if (!$borrower) {
            return ApiResponse::notFound(__('borrower.not_found'));
        }

        $removed = app(\App\Services\Borrower\CreditProfile\ClientCreditProfileService::class)
            ->remove($borrower, auth()->id());

        if (!$removed) {
            return ApiResponse::notFound(__('borrower.credit_profile_not_found'));
        }

        return ApiResponse::success(null, __('borrower.credit_profile_removed'));
    }

    /**
     * Resolve the appropriate parser service based on configuration.
     */
    /**
     * Tell the borrower their credit report has been saved to their profile:
     * the bell, its realtime push and an email, from their own partner's
     * mailbox (the partner id is passed explicitly - the ambient partner here is
     * the host who saved it).
     *
     * After the save has committed and in its own try/catch: a notification
     * problem is logged, never allowed to undo or fail a saved credit profile.
     */
    private function notifyBorrowerCreditProfileUpdated(\App\Models\Borrower\Borrower $borrower): void
    {
        try {
            $email = $borrower->email ?: $borrower->user?->email;
            $name = personName($borrower->first_name, $borrower->middle_name, $borrower->last_name);

            app(Notifier::class)->send(
                'borrower.credit_profile.updated',
                $borrower,
                [],
                mail: $email
                    ? (new \App\Mail\Borrower\CreditProfileUpdatedMail($name, $borrower->partner_id))->to($email)
                    : null
            );
        } catch (\Throwable $e) {
            Log::error(__('credit_profile_request.log_borrower_notify_failed', [
                'borrower_id' => $borrower->id,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Whether a parse found anything a credit report has: a score, an
     * account, an inquiry, an address or the person it is about. The
     * parsers always fill delinquent_accounts and summary, so those don't count.
     */
    private function looksLikeCreditReport(array $extracted): bool
    {
        $metrics = $extracted['metrics'] ?? [];
        foreach (['fico_score', 'paydex_score', 'experian_intelliscore', 'equifax_score'] as $score) {
            if (!empty($metrics[$score])) {
                return true;
            }
        }

        foreach (['trade_accounts', 'inquiries', 'addresses'] as $list) {
            if (!empty($extracted[$list])) {
                return true;
            }
        }

        $personal = $extracted['personal_info'] ?? [];
        foreach (['name', 'ssn', 'dob'] as $field) {
            if (!empty($personal[$field])) {
                return true;
            }
        }

        return false;
    }

    private function getParserService(string $mode, ?int $partnerId = null): \App\Services\FCH\Common\Borrower\CreditProfileParserInterface
    {
        if ($mode === 'openai') {
            $apiKey = \App\Models\Common\AppConfiguration::get('credit_profile_openai_api_key', '', 'credit_profile', $partnerId);

            // If no key for this partner, try host-level config
            if (empty($apiKey)) {
                $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
                $apiKey = \App\Models\Common\AppConfiguration::get('credit_profile_openai_api_key', '', 'credit_profile', $hostPartner?->id);
                $model = \App\Models\Common\AppConfiguration::get('credit_profile_openai_model', 'gpt-4o-mini', 'credit_profile', $hostPartner?->id);
            } else {
                $model = \App\Models\Common\AppConfiguration::get('credit_profile_openai_model', 'gpt-4o-mini', 'credit_profile', $partnerId);
            }

            if (empty($apiKey)) {
                Log::warning('OpenAI parser selected but no API key configured, falling back to manual parser');
                return new \App\Services\FCH\Common\Borrower\IsoftpullParserService();
            }

            return new \App\Services\FCH\Common\Borrower\OpenAiParserService($apiKey, $model);
        }

        return new \App\Services\FCH\Common\Borrower\IsoftpullParserService();
    }
}
