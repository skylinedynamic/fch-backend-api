<?php

namespace App\Services\Borrower\Document;

use App\Helpers\ApiResponse;
use App\Http\Resources\Global\Document\BorrowerDocTypeResource;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\Document;
use App\Models\User\User;
use App\Traits\UploadsFiles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    use UploadsFiles;

    private function resolveDirectory(User $user): string
    {
        return $directory
            ?? ($user->employee?->id
                ? $user->employee->id . '/documents'
                : ('uploads/documents/' . $user->id));
    }

    public function listForUser(Request $request)
    {
    
        $borrowerDocTypeId = $request->query('borrower_doc_type_id');
        $fileName = $request->query('file_name');
        $searchQuery = $request->query('query');
       

        return Document::query()
            ->ownedBy(Auth::id())
            ->type($borrowerDocTypeId)
            ->fileName($fileName)
            ->search($searchQuery)
            ->orderByDesc('created_at')
            ->get();
    }

    private function performUploadAndPersist(
        UploadedFile $file,
        ?int $borrowerDocTypeId,
        ?string $name,
    ): array {

        $user = Auth::user();
        $directory = $this->resolveDirectory($user);
        $meta = $this->uploadFile($file, $directory);

        try {
            $id = DB::transaction(function () use ($user, $borrowerDocTypeId, $name, $meta) {

                if ($borrowerDocTypeId) {
                    $docu_type = DB::table('borrower_doc_types')
                        ->where('id', $borrowerDocTypeId)
                        ->first();

                    $name = $docu_type->name;
                }

                return DB::table('documents')->insertGetId([
                    'user_id' => $user->id,
                    'borrower_doc_type_id' => $borrowerDocTypeId,
                    'name' => $name,
                    'path' => $meta['path'],
                    'disk' => $meta['disk'],
                    'original_name' => $meta['original_name'] ?? null,
                    'extension' => $meta['extension'] ?? null,
                    'mime' => $meta['mime'] ?? null,
                    'size' => $meta['size'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return [
                'id' => $id,
                'user_id' => $user->id,
                'borrower_doc_type_id' => $borrowerDocTypeId,
                'name' => $name,
                'path' => $meta['path'],
                'disk' => $meta['disk'],
                'url' => $meta['url'] ?? null,
                'original_name' => $meta['original_name'] ?? null,
                'extension' => $meta['extension'] ?? null,
                'mime' => $meta['mime'] ?? null,
                'size' => $meta['size'] ?? null,
            ];
        } catch (\Throwable $e) {
            try {
                if (isset($meta['disk'], $meta['path'])) {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                }
            } catch (\Throwable $cleanupEx) {
                Log::warning('Failed to cleanup uploaded file after DB error', [
                    'user_id' => $user->id,
                    'path' => $meta['path'] ?? null,
                    'disk' => $meta['disk'] ?? null,
                    'error' => $cleanupEx->getMessage(),
                ]);
            }

            throw new \RuntimeException(__('borrower.upload_failed'));
        }
    }


    public function uploadAndCreate(UploadedFile $file, ?int $borrowerDocTypeId = null, ?string $name = null): array
    {
        try {
            return $this->performUploadAndPersist($file, $borrowerDocTypeId, $name);
        } catch (\Throwable $e) {
            Log::error('Document upload failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('borrower.upload_failed'));
        }
    }


    public function uploadManyAndCreate(array $files, ?int $borrowerDocTypeId = null, ?string $name = null): array
    {
        $documents = [];

        foreach ($files as $index => $file) {
            try {
                if (!$file instanceof UploadedFile) {
                    throw new \InvalidArgumentException(__('borrower.each_file_must_be_file'));
                }

                $documents[] = $this->performUploadAndPersist($file, $borrowerDocTypeId, $name);
            } catch (\Throwable $e) {
                Log::error('Document upload failed (multi)', [
                    'user_id' => Auth::id(),
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException(__('borrower.upload_failed'));
            }
        }

        return $documents;
    }

    /**
     * Remove a document by id for the authenticated borrower.
     * Returns true on success, false if not found or not owned.
     */
    public function removeById(int $id): bool
    {
        try {
            $doc = Document::query()
                ->ownedBy(Auth::id())
                ->whereKey($id)
                ->first();

            if (!$doc) {
                return false;
            }

            // Attempt to delete underlying file; ignore failures and proceed to soft delete.
            try {
                if ($doc->path) {
                    $this->deleteFile($doc->path, $doc->disk);
                }
            } catch (\Throwable $e) {
                // log but do not block deletion
                Log::warning('Failed to delete document file', [
                    'user_id' => Auth::id(),
                    'document_id' => $id,
                    'path' => $doc->path,
                    'disk' => $doc->disk,
                    'error' => $e->getMessage(),
                ]);
            }

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