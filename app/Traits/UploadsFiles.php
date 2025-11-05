<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

trait UploadsFiles
{
    /**
     * Resolve target disk. Defaults to app's configured filesystem default.
     */
    protected function resolveDisk(?string $disk = null): string
    {
        return $disk ?: config('filesystems.default', 'local');
    }

    /**
     * Generate a safe unique filename preserving extension unless overridden.
     */
    protected function makeFilename(UploadedFile $file, ?string $filename = null): string
    {
        if ($filename) {
            return $filename;
        }

        $extension = $file->getClientOriginalExtension();
        return Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
    }

    /**
     * Build a public URL for stored file if possible.
     */
    protected function fileUrl(string $path, ?string $disk = null): ?string
    {
        $disk = $this->resolveDisk($disk);
        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable $e) {
            // Fallback for local public disk
            if ($disk === 'public') {
                return asset('storage/' . ltrim($path, '/'));
            }
        }

        return null;
    }

    /**
     * Upload a single file to the given directory on selected disk.
     * Returns metadata including path and URL.
     */
    protected function uploadFile(UploadedFile $file, string $directory = 'uploads', ?string $disk = null, ?string $filename = null, bool $public = true): array
    {
        $disk = $this->resolveDisk($disk);
        $filename = $this->makeFilename($file, $filename);

        $options = $public ? ['visibility' => 'public'] : [];
        $path = Storage::disk($disk)->putFileAs(trim($directory, '/'), $file, $filename, $options);

        return [
            'disk'          => $disk,
            'directory'     => trim($directory, '/'),
            'path'          => $path,
            'url'           => $this->fileUrl($path, $disk),
            'original_name' => $file->getClientOriginalName(),
            'extension'     => $file->getClientOriginalExtension(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
        ];
    }

    /**
     * Upload multiple files. Accepts array/Collection of UploadedFile instances.
     */
    protected function uploadFiles(array|Collection $files, string $directory = 'uploads', ?string $disk = null, bool $public = true): array
    {
        $results = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $results[] = $this->uploadFile($file, $directory, $disk, null, $public);
            }
        }
        return $results;
    }

    /**
     * Upload from request key, supporting single or multiple files (file[]).
     * Returns metadata array or list of arrays.
     */
    protected function uploadFromRequest(Request $request, string $key = 'file', string $directory = 'uploads', ?string $disk = null, bool $public = true): array
    {
        $files = $request->file($key);
        if (!$files) {
            return [];
        }

        if (is_array($files) || $files instanceof Collection) {
            return $this->uploadFiles($files, $directory, $disk, $public);
        }

        return $this->uploadFile($files, $directory, $disk, null, $public);
    }

    /**
     * Delete a file by storage path on selected disk.
     */
    protected function deleteFile(string $path, ?string $disk = null): bool
    {
        return Storage::disk($this->resolveDisk($disk))->delete($path);
    }
}