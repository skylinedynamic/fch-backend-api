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
}
