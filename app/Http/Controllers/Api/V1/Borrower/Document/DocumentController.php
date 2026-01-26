<?php

namespace App\Http\Controllers\Api\V1\Borrower\Document;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Document\UploadDocumentRequest;
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
            $documents = $this->documentService->listForUser($request);
            
            // groupBy returns a collection of collections, so we need to flatten or map it
            $payload = $documents->map(function ($group) {
                return DocumentResource::collection($group);
            });
            
            return ApiResponse::success($payload, __('borrower.documents_fetched_successfully'));

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Document Update
     */
    public function store(UploadDocumentRequest $request)
    {
        try {
            return $this->documentService->createBorrowerDocuments($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
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