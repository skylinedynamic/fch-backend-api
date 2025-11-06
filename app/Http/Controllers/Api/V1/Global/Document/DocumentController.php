<?php

namespace App\Http\Controllers\Api\V1\Global\Document;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Document\UploadDocumentRequest;
use App\Models\User\User;
use App\Services\Global\Document\DocumentService;

class DocumentController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * Document Update
     */
    public function store(UploadDocumentRequest $request)
    {
        $data = $request->validated();

        $user = User::with('employee')->find($data['user_id']);
        if (!$user || !$user->employee) {
            return ApiResponse::notFound(__('common.document.user_not_found'));
        }

        try {
            // Multi-file support: accept 'files' array or single 'file'
            if ($request->hasFile('files')) {
                $result = $this->documentService->uploadManyAndCreate(
                    $user,
                    $request->file('files'),
                    $data['borrower_doc_type_id'] ?? null,
                    $data['name'] ?? null,
                    $data['disk'] ?? null,
                    null
                );

                return ApiResponse::success($result, __('common.document.upload_success'), 201);
            }

            $document = $this->documentService->uploadAndCreate(
                $user,
                $request->file('file'),
                $data['borrower_doc_type_id'] ?? null,
                $data['name'] ?? null,
                $data['disk'] ?? null,
                null
            );

            return ApiResponse::success($document, __('common.document.upload_success'), 201);
        } catch (\Throwable $e) {
            return ApiResponse::error(__('common.document.upload_failed'));
        }
    }
}