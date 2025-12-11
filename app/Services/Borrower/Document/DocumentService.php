<?php

namespace App\Services\Borrower\Document;

use App\Helpers\ApiResponse;
use App\Http\Resources\Global\Document\BorrowerDocTypeResource;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DocumentService
{

    public function listForUser(Request $request)
    {
        return BorrowerDocument::query()
            ->ownedBy(Auth::id())
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('document_type_id');
    }

    /**
     * Create multiple BorrowerDocument records from a list of file paths.
     */
    public function createBorrowerDocuments(array $data)
    {
        $documents = [];
        $user = Auth::user();

        $borrowerId = $user->id;

        foreach ($data['files'] as $path) {
            try {
                $doc = BorrowerDocument::create([
                    'borrower_id' => $borrowerId,
                    'document_type_id' => $data['document_type_id'],
                    'file_path' => $path,
                    'file_name' => basename($path),
                    'created_by' => $user->id,
                ]);
                
                $documents[] = $doc->toArray();
                
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
     * Remove a document by id for the authenticated borrower.
     */
    public function removeById(int $id): bool
    {
        try {
            $doc = BorrowerDocument::query()
                ->ownedBy(Auth::id())
                ->whereKey($id)
                ->first();

            if (!$doc) {
                return false;
            }

            $doc->deleted_by = Auth::id();
            $doc->save();
            $doc->delete();
            
            return true;
        } catch (\Throwable $e) {
            Log::error('Document removal failed', [
                'user_id' => Auth::id(),
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
            $documentTypes = BorrowerDocType::orderBy('sort_order', 'asc')->get();

            return ApiResponse::success(
                BorrowerDocTypeResource::collection($documentTypes),
                __('borrower.document_types_retrieved')
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(__('borrower.document_types_fetch_failed'));
        }
    }
}