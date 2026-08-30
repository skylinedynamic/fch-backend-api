<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use App\Services\Global\SharePoint\SharePointService;

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
     * Ensure target directory exists on selected disk.
     */
    protected function ensureDirectory(string $directory, ?string $disk = null): void
    {
        $disk = $this->resolveDisk($disk);
        $dir = trim($directory, '/');
        try {
            // Safe to call even if directory exists; on S3 it creates a placeholder prefix.
            Storage::disk($disk)->makeDirectory($dir);
        } catch (\Throwable $e) {
            // Ignore; subsequent write will still attempt to create path if supported.
        }
    }

    /**
     * Upload a single file to the given directory on selected disk.
     * Returns metadata including path and URL.
     */
    protected function uploadFile(UploadedFile $file, string $directory = 'uploads', ?string $disk = null, ?string $filename = null, bool $public = true): array
    {
        $disk = $this->resolveDisk($disk);
        $filename = $this->makeFilename($file, $filename);
        $dir = trim($directory, '/');

        // Unified: SharePoint branch
        if ($disk === 'sharepoint') {
            $sp = app(SharePointService::class);
            $item = $sp->uploadFile($file, $dir, $filename, null);
            $path = ($dir ? $dir . '/' : '') . $filename;
            return [
                'disk'          => 'sharepoint',
                'directory'     => $dir,
                'path'          => $path,
                'url'           => $item['webUrl'] ?? $item['downloadUrl'] ?? null,
                'sp_item_id'    => $item['id'] ?? null,
                'original_name' => $file->getClientOriginalName(),
                'extension'     => $file->getClientOriginalExtension(),
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ];
        }

        // Default: Local/S3 via Laravel Storage
        $this->ensureDirectory($dir, $disk);
        $options = $public ? ['visibility' => 'public'] : [];
        $path = Storage::disk($disk)->putFileAs($dir, $file, $filename, $options);

        return [
            'disk'          => $disk,
            'directory'     => $dir,
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
        $dir = trim($directory, '/');

        if ($this->resolveDisk($disk) === 'sharepoint') {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $results[] = $this->uploadFile($file, $dir, 'sharepoint', null, $public);
                }
            }
            return $results;
        }

        $this->ensureDirectory($dir, $disk);
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $results[] = $this->uploadFile($file, $dir, $disk, null, $public);
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
     * Upload a single file to Microsoft SharePoint via Graph API.
     */
    protected function uploadFileToSharePoint(UploadedFile $file, string $directory = 'uploads', ?string $siteId = null, ?string $filename = null): array
    {
        $sp = app(SharePointService::class);
        return $sp->uploadFile($file, $directory, $filename, $siteId);
    }

    /**
     * Upload multiple files to Microsoft SharePoint via Graph API.
     */
    protected function uploadFilesToSharePoint(array|Collection $files, string $directory = 'uploads', ?string $siteId = null): array
    {
        $results = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $results[] = $this->uploadFileToSharePoint($file, $directory, $siteId);
            }
        }
        return $results;
    }

    /**
     * Delete a file by storage path on selected disk.
     */
    protected function deleteFile(string $path, ?string $disk = null): bool
    {
        $disk = $this->resolveDisk($disk);
        if ($disk === 'sharepoint') {
            $sp = app(SharePointService::class);
            return $sp->deleteItemByPath($path, null);
        }
        return Storage::disk($disk)->delete($path);
    }

    /**
     * Delete a SharePoint file by drive item ID.
     */
    protected function deleteSharePointFileById(string $itemId, ?string $siteId = null): bool
    {
        $sp = app(SharePointService::class);
        return $sp->deleteItemById($itemId, $siteId);
    }

    /**
     * Delete a SharePoint file by path under drive root.
     */
    protected function deleteSharePointFileByPath(string $path, ?string $siteId = null): bool
    {
        $sp = app(SharePointService::class);
        return $sp->deleteItemByPath($path, $siteId);
    }
}