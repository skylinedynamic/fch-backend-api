<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Http\Controllers\Controller;
use App\Services\FCH\Partner\PartnerDocumentService;
use App\Http\Resources\FCH\Partner\PartnerDocumentResource;
use App\Helpers\ApiResponse;
use App\Models\Partner\PartnerDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
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
     * Get Partner Documents.
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
     * Store Partner Document.
     */
    public function store(StorePartnerDocumentRequest $request)
    {
        $result = $this->partnerDocumentService->store($request->validated());

        if ($result instanceof \Illuminate\Support\Collection) {
            return ApiResponse::success(
                PartnerDocumentResource::collection($result),
                __('partner.success.document_uploaded'),
                201
            );
        }

        if ($result) {
            return ApiResponse::success(
                new PartnerDocumentResource($result),
                __('partner.success.document_uploaded'),
                201
            );
        }

        return ApiResponse::validationError([], __('partner.validation.document_file.required'));
    }

    /**
     * Update Partner Document.
     */
    // public function update(UpdatePartnerDocumentRequest $request, int $id)
    // {
    //     // Try to find by explicit ID first
    //     // If not found by ID, attempt to find by [partner_id, entity_type, entity_id, document_type]
    //     if (!$document) {
    //         $entityType = $request->input('entity_type');
    //         $entityId = $request->input('entity_id');
    //         $documentType = $request->input('document_type');

    //             // Only add where clauses if the table has these columns
    //             if (Schema::hasColumn('partner_documents', 'entity_type') && Schema::hasColumn('partner_documents', 'entity_id') && Schema::hasColumn('partner_documents', 'document_type')) {
    //                 $document = PartnerDocument::where('partner_id', auth()->user()->partner_id)
    //                     ->where('entity_type', $entityType)
    //                     ->where('entity_id', $entityId)
    //                     ->where('document_type', $documentType)
    //                     ->first();
    //             } else {
    //                 // Fallback: try to match by document_name using basename of the first provided file or document_name
    //                 $basename = null;
    //                 $files = $request->input('files', []);
    //                 if (is_array($files) && !empty($files)) {
    //                     $first = $files[0];
    //                     if (is_string($first)) {
    //                         $basename = basename(ltrim($first, '/'));
    //                     }
    //                 }
    //                 if (!$basename && $request->filled('document_name')) {
    //                     $basename = $request->input('document_name');
    //                 }

    //                 if ($basename) {
    //                     $document = PartnerDocument::where('partner_id', auth()->user()->partner_id)
    //                         ->where('document_name', $basename)
    //                         ->first();
    //                 }
    //             }

    //             // If still not found, create a new one using the store service
    //             if (!$document) {
    //                 $data = $request->validated();
    //                 $data['partner_id'] = auth()->user()->partner_id;

    //                 $result = $this->partnerDocumentService->store($data);

    //                 if ($result instanceof \Illuminate\Support\Collection) {
    //                     return ApiResponse::success(
    //                         PartnerDocumentResource::collection($result),
    //                         __('partner.success.document_uploaded'),
    //                         201
    //                     );
    //                 }

    //                 return ApiResponse::success(
    //                     new PartnerDocumentResource($result),
    //                     __('partner.success.document_uploaded'),
    //                     201
    //                 );
    //             }
    //         } else {
    //             return ApiResponse::notFound('Document not found');
    //         }
    //     }

    //     // Update the existing document
    //     // $updatedDocument = $this->partnerDocumentService->update($document, $request->validated());

    //     return ApiResponse::success(
    //         new PartnerDocumentResource($updatedDocument),
    //         __('partner.success.document_updated')
    //     );
    // }

    /**
     * Download Partner Document.
     */
    public function download(int $id)
    {
        $document = PartnerDocument::find($id);

        if (!$document) {
            return ApiResponse::notFound('Document not found');
        }

        $urlPath = parse_url($document->file_path, PHP_URL_PATH);
        $relativePath = ltrim($urlPath, '/'); 
        $filePath = realpath(public_path($relativePath));
        $storageRoot = realpath(public_path('storage'));

        // Only a file inside public/storage is served: a stored path that
        // climbs out of it (../) must not reach anything else on the server.
        if (!$filePath || !$storageRoot || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            return ApiResponse::notFound('File not found on server');
        }

        // Get MIME type
        $mimeType = mime_content_type($filePath);

        return response()->download(
            $filePath,
            $document->document_name,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $document->document_name . '"',
            ]
        );
    }

    /**
     * Delete Partner Document.
     */
    public function delete(int $id)
    {
        $document = PartnerDocument::find($id);

        if (!$document) {
            return ApiResponse::notFound('Document not found');
        }

        $this->partnerDocumentService->delete($document);

        return ApiResponse::success(null, __('partner.success.document_deleted'));
    }
}
