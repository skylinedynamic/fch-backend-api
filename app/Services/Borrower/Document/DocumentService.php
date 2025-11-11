<?php

namespace App\Services\Borrower\Document;

use App\Models\User\User;
use App\Traits\UploadsFiles;
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

    public function listForUser(?int $borrowerDocTypeId = null): array
    {
        
        $query = DB::table('documents')
            ->where('user_id', Auth::id())
            ->when($borrowerDocTypeId, function ($q) use ($borrowerDocTypeId) {
                $q->where('borrower_doc_type_id', $borrowerDocTypeId);
            })
            ->whereNull('deleted_at')
            ->orderByDesc('created_at');

        $rows = $query->get();

        $documents = [];
        foreach ($rows as $row) {
            $documents[] = [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'borrower_doc_type_id' => $row->borrower_doc_type_id,
                'name' => $row->name,
                'path' => $row->path,
                'disk' => $row->disk,
                'url' => $this->fileUrl($row->path, $row->disk),
                'original_name' => $row->original_name,
                'extension' => $row->extension,
                'mime' => $row->mime,
                'size' => $row->size,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        return $documents;
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
}