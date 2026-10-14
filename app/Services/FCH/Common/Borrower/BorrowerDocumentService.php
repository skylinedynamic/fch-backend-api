<?php

namespace App\Services\FCH\Common\Borrower;

use App\Helpers\ApiResponse;
use App\Http\Resources\Global\Document\BorrowerDocTypeResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BorrowerDocumentService
{
    /**
     * Create multiple BorrowerDocument records from a list of file paths.
     */
    public function createBorrowerDocuments(array $data)
    {
        $documents = [];
        $user = Auth::user();
        $borrowerId = $data['borrower_id'];

        if (!Borrower::visibleTo($user)->whereKey($borrowerId)->exists()) {
            return ApiResponse::notFound(__('borrower.not_found'));
        }

        foreach ($data['file_path'] as $index => $path) {
            try {
                $docName = $data['document_name'][$index] ?? basename($path);
                $documents[] = $this->createDocument($borrowerId, $path, $docName, $data);
            } catch (\Throwable $e) {
                Log::error(__('borrower_validation.upload_failed'), [
                    'user_id' => $user->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException($e->getMessage());
            }
        }

        return ApiResponse::success($documents, __('borrower.upload_success'), 201);
    }

    /**
     * Create individual borrower document record
     */
    private function createDocument(int $borrowerId, $file, string $docName, array $data): BorrowerDocument
    {
        $size = null;
        $borrowerPath = $this->normalizePath($borrowerId, $file);
        $dbPath = str_replace("borrowers/{$borrowerId}/", "", $borrowerPath);

        // Actually store the file if it's an UploadedFile
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            $file->storeAs("borrowers/{$borrowerId}", $dbPath, 'public');
        }

        if (Storage::disk('public')->exists($borrowerPath)) {
             try {
                $size = $this->formatSize(Storage::disk('public')->size($borrowerPath));
             } catch (\Exception $e) {
                // Ignore size error
             }
        } 
        elseif (Storage::disk('public')->exists(ltrim($file, '/'))) {
             try {
                $size = $this->formatSize(Storage::disk('public')->size(ltrim($file, '/')));
             } catch (\Exception $e) {
             }
        }

        $createData = [
            'borrower_id'      => $borrowerId,
            'file_name'        => $docName,
            'document_type_id' => $data['document_type_id'] ?? null,
            'file_path'        => $dbPath, // Stored as just the filename
            'file_size'        => $size,
            'status'           => BorrowerDocument::STATUS_UPLOADED,
            'created_by'       => Auth::id(),
        ];

        return BorrowerDocument::create($createData);
    }

    /**
     * Normalize file path for borrowers
     */
    private function normalizePath(int $borrowerId, $file): string
    {
        // If it's an UploadedFile object, put it in borrowers/{id}/
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            return "borrowers/{$borrowerId}/" . $file->getClientOriginalName();
        }

        // If it's a string, ensure it starts with borrowers/{id}/
        $path = ltrim($file, '/');
        if (!str_starts_with($path, "borrowers/{$borrowerId}/")) {
            return "borrowers/{$borrowerId}/" . $path;
        }

        return $path;
    }

    /**
     * Update Document Status
     */
    public function updateStatus(int $id, array $data)
    {
        try {
            $doc = BorrowerDocument::visibleTo(Auth::user())->find($id);
            if (!$doc) {
                return ApiResponse::notFound(__('borrower.document_not_found'));
            }

            $updateData = [
                'status' => $data['status'],
                'updated_by' => Auth::id(),
            ];

            if ($data['status'] === BorrowerDocument::STATUS_REJECTED) {
                $updateData['rejection_reason'] = $data['rejection_reason'] ?? null;
            } else {
                $updateData['rejection_reason'] = null;
            }

            $doc->update($updateData);

            return ApiResponse::success($doc, __('borrower.document_status_updated'));
        } catch (\Throwable $e) {
            Log::error('Document status update failed', [
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('borrower.document_status_update_failed'));
        }
    }

    /**
     * Remove a document by id.
     */
    public function removeById(int $id): bool
    {
        try {
            $doc = BorrowerDocument::visibleTo(Auth::user())->find($id);

            if (!$doc) {
                return false;
            }

            $doc->deleted_by = Auth::id();
            $doc->save();
            $doc->delete();
            
            return true;
        } catch (\Throwable $e) {
            Log::error('Document removal failed', [
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('borrower.document_delete_failed'));
        }
    }

    /**
     * Get Document Types
     */
    public function documentTypes()
    {
        try {
            $documentTypes = BorrowerDocType::orderBy('id', 'asc')->get();

            return ApiResponse::success(
                BorrowerDocTypeResource::collection($documentTypes),
                __('borrower.document_types_retrieved')
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(__('borrower.document_types_fetch_failed'));
        }
    }

    /**
     * Format file size.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
