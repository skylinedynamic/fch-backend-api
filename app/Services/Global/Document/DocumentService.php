<?php

namespace App\Services\Global\Document;

use App\Models\User\User;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DocumentService
{
    use UploadsFiles;

    /**
     * Upload a document for a user and create a DB record.
     * Returns a structured array of the created document and metadata.
     */
    public function uploadAndCreate(User $user, UploadedFile $file, ?int $borrowerDocTypeId = null, ?string $name = null, ?string $disk = null, ?string $directory = null): array
    {
        try {
            // Default directory: prefer employee-specific folder if available, otherwise user-based.
            $directory = $directory
                ?? ($user->employee?->id
                    ? $user->employee->id . '/documents'
                    : ('uploads/documents/' . $user->id));

            $meta = $this->uploadFile($file, $directory, $disk);

            $id = DB::table('documents')->insertGetId([
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
            Log::error('Document upload failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('common.document.upload_failed'));
        }
    }

    /**
     * Upload multiple documents and create DB records. Returns documents and errors arrays.
     *
     * @param array<int, UploadedFile> $files
     * @return array{documents: array<int, array>, errors: array<int, array>}
     */
    public function uploadManyAndCreate(User $user, array $files, ?int $borrowerDocTypeId = null, ?string $name = null, ?string $disk = null, ?string $directory = null): array
    {
        $directory = $directory
            ?? ($user->employee?->id
                ? $user->employee->id . '/documents'
                : ('uploads/documents/' . $user->id));

        $documents = [];
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                if (!$file instanceof UploadedFile) {
                    throw new \InvalidArgumentException('Invalid file input');
                }

                $meta = $this->uploadFile($file, $directory, $disk);

                $id = DB::table('documents')->insertGetId([
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

                $documents[] = [
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
                Log::error('Document upload failed (multi)', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'documents' => $documents,
            'errors' => $errors,
        ];
    }
}