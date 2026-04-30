<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Borrower;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Models\Borrower\BorrowerDocument;
use App\Services\FCH\Common\Tier\TierService;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Http\Resources\FCH\Borrower\BorrowerDetailsResource;
use App\Http\Resources\FCH\Borrower\BorrowerProfileResource;
use App\Services\FCH\Common\Borrower\BorrowerDocumentService;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\FetchBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateDocumentStatusRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UploadBorrowerDocumentRequest;
use App\Http\Resources\FCH\Borrower\BorrowerPersonalDetailsResource;
use App\Http\Resources\FCH\Borrower\BorrowerBusinessFinancialsResource;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerBusinessFinancialsRequest;

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
        try {
            return $this->borrowerService->list($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.list_failed'));
        }
    }

    /**
     * Store Borrower.
     */
    public function store(StoreBorrowerRequest $request)
    {
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
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }

    /**
     * Update Borrower Business Financials.
     */
    public function BusinessFinancials(UpdateBorrowerBusinessFinancialsRequest $request, string $id)
    {
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
            return ApiResponse::serverError(__('borrower.update_failed'));
        }
    }

    /**
     * Update Borrower.
     */
    public function update(UpdateBorrowerRequest $request, string $id)
    {
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
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.update_failed'));
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
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:borrowers,id',
        ]);

        return $this->borrowerService->delete($request->ids);
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
            Mail::to($user->email)->send(new BorrowerAccountCreatedMail($data));
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
            $document = BorrowerDocument::where('id', $documentId)->first();
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
            $filePath = storage_path('app/public/' . $relativePath);

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
}
