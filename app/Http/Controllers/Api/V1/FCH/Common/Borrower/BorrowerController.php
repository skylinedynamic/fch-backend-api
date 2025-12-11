<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Borrower;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\FetchBorrowerRequest;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Helpers\ApiResponse;
use App\Services\Borrower\Document\DocumentService;
use Illuminate\Http\Request;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use Illuminate\Support\Facades\Mail;
use App\Models\Borrower\BorrowerDocument;
use Illuminate\Support\Facades\Storage;

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
            $input = $request->validated();
            
            // Sanitize pagination
            $perPage = isset($input['per_page']) ? (int) $input['per_page'] : null;
            $perPage = $perPage !== null ? max(1, min($perPage, 100)) : null;

            $filters = [
                'per_page' => $perPage,
                'page' => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search' => isset($input['search']) ? trim((string) $input['search']) : null,
                'partner_id' => $input['partner_id'] ?? null,
                'current_tier' => $input['current_tier'] ?? null,
                'assign_id' => $input['assign_id'] ?? null,
                'filters' => $input['filters'] ?? null,
                'sort_field' => $input['sort_field'] ?? null,
                'sort_order' => $input['sort_order'] ?? null,
            ];

            return $this->borrowerService->list($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.error.list_failed'));
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

            if ($user && $plainPassword) {
                // Get partner info for the email
                $partnerInfo = auth()->user()->partner;
                if ($partnerInfo) {
                    $this->sendAccountCreatedMail($user, $partnerInfo, $plainPassword);
                }
            }

            return ApiResponse::success(
                new BorrowerResource($borrower),
                __('borrower.success.created'),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.error.create_failed'));
        }
    }

    /**
     * Update Borrower.
     */
    public function update(UpdateBorrowerRequest $request, string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int)$id);
            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.error.not_found'));
            }

            $data = $request->validated();
            $updatedBorrower = $this->borrowerService->update($borrower, $data);

            return ApiResponse::success(
                new BorrowerResource($updatedBorrower),
                __('borrower.success.updated')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.error.update_failed'));
        }
    }

    /**
     * Get Borrower Details.
     */
    public function show(string $id)
    {
        try {
            $borrower = $this->borrowerService->getDetails((int)$id);

            if (!$borrower) {
                return ApiResponse::notFound(__('borrower.error.not_found'));
            }

            return ApiResponse::success(
                new BorrowerResource($borrower),
                __('borrower.success.retrieved_details')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.error.fetch_failed'));
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
     * Get All Users
     */
    public function getAllUsers(Request $request)
    {
        try {
            $users = $this->borrowerService->getAllUsers();

            return ApiResponse::success(
                $users,
                __('borrower.success.users_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.error.users_fetch_failed'));
        }
    }

    /**
     * Get Document Types
     */
    public function documentTypes()
    {
        try {
            $documentService = new DocumentService();
            return $documentService->documentTypes();

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.document_types_fetch_failed'));
        }
    }

    
    /**
     * Get Tiers List
     * TODO: When tiers table is created, modify to fetch from database
     */
    public function getTiers()
    {
        try {
            // Get tiers from config file
            // In the future, this can be modified to fetch from database
            $tiers = config('tiers.list', []);

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
            'subject' => 'Welcome to '.$businessInfo->company_name,
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
     * Get Borrower Count Statistics.
     * Automatically filtered based on authenticated user type:
     * - Borrower: Returns zeros
     * - Partner: Returns counts for their partner_id
     * - Admin: Returns all counts
     */
    public function stats()
    {
        try {
            // Service automatically handles filtering based on auth user type
            $data = $this->borrowerService->getBorrowerStats();

            return ApiResponse::success($data, __('borrower.stats_retrieved') ?? 'Stats retrieved successfully');
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('borrower.stats_retrieved_failed') ?? 'Failed to retrieve stats', 500);
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
            $documents = $this->borrowerService->getBorrowerDocuments($borrowerId);
            return ApiResponse::success($documents, 'Documents retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to retrieve documents');
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
                return ApiResponse::notFound('Document not found');
            }

            // Prefer file_path, fallback to document column if file_path is empty
            $urlPath = $document->file_path ?: $document->document;

            // If still empty
            if (!$urlPath) {
                 return ApiResponse::notFound('File path not found in record');
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
                return ApiResponse::notFound('File not found on server');
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
             return ApiResponse::serverError('Download failed');
        }
    }
}
