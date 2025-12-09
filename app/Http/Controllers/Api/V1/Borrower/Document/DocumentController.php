<?php

namespace App\Http\Controllers\Api\V1\Borrower\Document;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Document\GetDocuRequest;
use App\Http\Requests\Api\V1\Borrower\Document\UploadDocumentRequest;
use App\Models\User\User;
use App\Services\Borrower\Document\DocumentService;
use App\Http\Resources\Borrower\Document\DocumentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * Document List
     */
    public function index(GetDocuRequest $request)
    {
        try {
            $documents = $this->documentService->listForUser($request);
            $payload = DocumentResource::collection($documents);
            return ApiResponse::success($payload, __('borrower.documents_fetched_successfully'));

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.documents_fetch_failed'));
        }
    }

    /**
     * Document Update
     */
    public function store(UploadDocumentRequest $request)
    {
        $data = $request->validated();

        $authUser = Auth::user();
        $user = User::with('employee')->find($authUser->id);

        try {
            $fileInput = $request->file('files');
            if (is_array($fileInput)) {
                $result = $this->documentService->uploadManyAndCreate(
                    $fileInput,
                    $data['borrower_doc_type_id'] ?? null,
                    $data['file_name'] ?? null,
                );

                $payload = DocumentResource::collection(collect($result)->map(fn($row) => (object)$row));
                return ApiResponse::success($payload, __('borrower.upload_success'), 201);
            }

            $document = $this->documentService->uploadAndCreate(
                $fileInput,
                $data['borrower_doc_type_id'] ?? null,
                $data['file_name'] ?? null,
            );

            return ApiResponse::success(new DocumentResource((object)$document), __('borrower.upload_success'), 201);
        } catch (\Throwable $e) {
            return ApiResponse::error(__('borrower.upload_failed'));
        }
    } 

    /**
     * Document Remove
     */
    public function destroy(Request $request)
    {
        try {
            $id = $request->route('id');
            $deleted = $this->documentService->removeById($id);
            if (!$deleted) {
                return ApiResponse::notFound(__('borrower.document_not_found'));
            }
            return ApiResponse::success(null, __('borrower.document_deleted_successfully'));
        } catch (\Throwable $e) {
            return ApiResponse::error(__('borrower.document_delete_failed'));
        }
    }


    /**
     * Get Document Types
     */
    public function documentTypes()
    {
        try {
            return $this->documentService->documentTypes();

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('borrower.document_types_fetch_failed'));
        }
    }

    
}