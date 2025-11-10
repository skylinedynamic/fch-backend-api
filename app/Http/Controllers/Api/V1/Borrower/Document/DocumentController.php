<?php

namespace App\Http\Controllers\Api\V1\Borrower\Document;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
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
    public function index(Request $request)
    {
        try {
            $borrowerDocTypeId = $request->query('borrower_doc_type_id');
            $documents = $this->documentService->listForUser($borrowerDocTypeId ? (int)$borrowerDocTypeId : null);
            
            $payload = DocumentResource::collection($documents);
            return ApiResponse::success($payload, __('borrower.documents_fetched_successfully'));
        
        } catch (\Throwable $e) {
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

                $payload = DocumentResource::collection($result);
                return ApiResponse::success($payload, __('borrower.upload_success'), 201);
            }

            $document = $this->documentService->uploadAndCreate(
                $fileInput,
                $data['borrower_doc_type_id'] ?? null,
                $data['file_name'] ?? null,
            );

            return ApiResponse::success(new DocumentResource($document), __('borrower.upload_success'), 201);
        } catch (\Throwable $e) {
            return ApiResponse::error(__('borrower.upload_failed'));
        }
    } 
}