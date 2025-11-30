<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Http\Controllers\Controller;
use App\Services\FCH\Partner\PartnerDocumentService;
use App\Http\Resources\FCH\Partner\PartnerDocumentResource;
use App\Helpers\ApiResponse;
use App\Models\Partner\PartnerDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerDocumentRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerDocumentRequest;

class PartnerDocumentController extends Controller
{
    protected PartnerDocumentService $partnerDocumentService;

    public function __construct(PartnerDocumentService $partnerDocumentService)
    {
        $this->partnerDocumentService = $partnerDocumentService;
    }

    /**
     * Get all documents for a specific partner.
     */
    public function index(Request $request)
    {
        $request->validate([
            'partner_id' => 'required|integer|exists:partners,id',
        ]);

        $documents = $this->partnerDocumentService->getDocumentsByPartnerId($request->partner_id);

        return ApiResponse::success(
            PartnerDocumentResource::collection($documents),
            __('partner.success.documents_retrieved')
        );
    }

    /**
     * Store a new partner document.
     */
    public function store(StorePartnerDocumentRequest $request)
    {
        $document = $this->partnerDocumentService->store($request->validated());

        return ApiResponse::success(
            new PartnerDocumentResource($document),
            __('partner.success.document_uploaded'),
            201
        );
    }

    /**
     * Update an existing partner document.
     */
    public function update(UpdatePartnerDocumentRequest $request, int $id)
    {
        $document = PartnerDocument::find($id);

        if (!$document) {
            return ApiResponse::notFound('Document not found');
        }

        $updatedDocument = $this->partnerDocumentService->update($document, $request->validated());

        return ApiResponse::success(
            new PartnerDocumentResource($updatedDocument),
            __('partner.success.document_updated')
        );
    }

    /**
     * Download a partner document.
     * Supports: images, videos, PDFs, Excel, ZIP, audio, and other file types.
     */
    public function download(int $id)
    {
        $document = PartnerDocument::find($id);
        
        if (!$document) {
            return ApiResponse::notFound('Document not found');
        }

        // Get the full path to the file
        $filePath = storage_path('app/public/' . $document->file_path);

        // Check if file exists
        if (!file_exists($filePath)) {
            return ApiResponse::notFound('File not found on server');
        }

        // Get MIME type
        $mimeType = mime_content_type($filePath);

        // Return file download with proper headers
        return response()->download(
            $filePath,
            $document->document_name,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $document->document_name . '"',
            ]
        );
    }
}
