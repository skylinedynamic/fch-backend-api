<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Exception;

trait UploadsFiles
{
    /**
     * Upload a single file to the given path and disk.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @param  string  $path
     * @param  string  $disk
     * @return array
     */
    public function uploadFile(UploadedFile $file, string $path, string $disk = 'public'): array
    {
        try {
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension;

            // Store file
            $storedPath = $file->storeAs($path, $filename, $disk);

            // Return response
            return [
                'path' => $storedPath,
                'url'  => Storage::disk($disk)->url($storedPath),
            ];
        } catch (Exception $e) {
            throw new Exception("File upload failed: " . $e->getMessage());
        }
    }

    /**
     * Upload multiple files to the given path and disk.
     *
     * @param  array  $files
     * @param  string  $path
     * @param  string  $disk
     * @return array
     */
    public function uploadFiles(array $files, string $path, string $disk = 'public'): array
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $uploadedFiles[] = $this->uploadFile($file, $path, $disk);
            }
        }

        return $uploadedFiles;
    }

    /**
     * Automatically upload from request (detects single or multiple files).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $key
     * @param  string  $path
     * @param  string  $disk
     * @return array|null
     */
    public function uploadFromRequest(Request $request, string $key, string $path, string $disk = 'public')
    {
        $files = $request->file($key);

        if (!$files) {
            return null;
        }

        // Handle multiple files
        if (is_array($files)) {
            return $this->uploadFiles($files, $path, $disk);
        }

        // Handle single file
        if ($files instanceof UploadedFile) {
            return $this->uploadFile($files, $path, $disk);
        }

        return null;
    }

    /**
     * Delete a file from the given disk.
     *
     * @param  string  $path
     * @param  string  $disk
     * @return bool
     */
    public function deleteFile(string $path, string $disk = 'public'): bool
    {
        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->delete($path);
            }
            return false;
        } catch (Exception $e) {
            throw new Exception("File deletion failed: " . $e->getMessage());
        }
    }
}
