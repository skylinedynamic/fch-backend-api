<?php

namespace App\Services\Global\FileManager;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FileManagerService
{
    private function getDisk()
    {
        return Storage::disk('public');
    }

    private function getFullPath($path)
    {
        // Sanitize path to prevent directory traversal
        $path = str_replace('..', '', $path);
        return trim(ltrim($path, '/'), '/');
    }

    private function getEncodedUrl($disk, $path)
    {
        return encodeUrl($disk->url($path));
    }

    /**
     * Get base path from request entity type and ID
     * Creates the directory if it doesn't exist
     */
    private function getBasePath($request)
    {
        // Construct base path directly from request
        if ($request->has('get_all') && $request->boolean('get_all')) {
            $basePath = $request->entity_type;
        } else {
            $basePath = $request->entity_type . "/" . $request->entity_id;
        }

        // Ensure the base path exists
        $disk = $this->getDisk();
        if (!$disk->exists($basePath)) {
            $disk->makeDirectory($basePath);
        }

        return $basePath;
    }

    public function listDocuments($path, $request)
    {
        // Prepend base path based on request entity
        $basePath = $this->getBasePath($request);
        $completePath = $basePath . $path;

        $fullPath = $this->getFullPath($completePath);
        $disk = $this->getDisk();

        if (!$disk->exists($fullPath)) {
            if ($fullPath !== '' && !$disk->exists($fullPath)) {
                return [];
            }
        }

        $files = $disk->files($fullPath);
        $directories = $disk->directories($fullPath);

        $data = [];

        // Normalize the path - ensure it starts with / and doesn't end with /
        $normalizedPath = '/' . trim($path, '/');
        if ($normalizedPath === '/') {
            $normalizedPath = '';
        }

        foreach ($directories as $directory) {
            $name = basename($directory);
            $data[] = [
                'name' => $name,
                'isDirectory' => true,
                'path' => $normalizedPath . '/' . $name,
                'updatedAt' => date('c', $disk->lastModified($directory)),
            ];
        }

        foreach ($files as $file) {
            $name = basename($file);
            $data[] = [
                'name' => $name,
                'isDirectory' => false,
                'path' => $normalizedPath . '/' . $name,
                'url' => $this->getEncodedUrl($disk, $file),
                'updatedAt' => date('c', $disk->lastModified($file)),
                'size' => $disk->size($file),
                'type' => $disk->mimeType($file),
            ];
        }

        return $data;
    }

    public function uploadFile($path, UploadedFile $file, $request)
    {
        $disk = $this->getDisk();
        try {
            if (!$file->isValid()) {
                throw new \Exception('Uploaded file is not valid: ' . $file->getErrorMessage());
            }

            // Prepend base path based on request entity
            $basePath = $this->getBasePath($request);

            // Normalize slashes between base and user path
            $path = '/' . ltrim($path, '/');
            $completePath = rtrim($basePath, '/') . $path;

            $fullPath = $this->getFullPath($completePath);

            // Clean filename and append timestamp
            $originalName = $file->getClientOriginalName();
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $basename = pathinfo($originalName, PATHINFO_FILENAME);

            // Sanitize: allow only alphanumeric, dashes, and underscores
            $safeName = preg_replace('/[^A-Za-z0-9\-\_]/', '_', $basename);
            $filename = time() . '_' . $safeName . '.' . $extension;

            // Ensure directory exists - defensive check
            if ($fullPath !== '' && !$disk->exists($fullPath)) {
                $disk->makeDirectory($fullPath);
            }

            // Store using putFileAs
            $pathResult = $disk->putFileAs($fullPath, $file, $filename);

            if (!$pathResult) {
                Log::error("File storage failed for disk 'public' and path: {$fullPath}/{$filename}. Check permissions and disk space.");
                throw new \Exception('Failed to store the uploaded file on the server.', 500);
            }

            return [
                'name' => $filename,
                'isDirectory' => false,
                'path' => rtrim($path, '/') . '/' . $filename,
                'url' => $this->getEncodedUrl($disk, $pathResult),
                'updatedAt' => date('c', $disk->lastModified($pathResult)),
                'size' => $disk->size($pathResult),
                'type' => $disk->mimeType($pathResult),
            ];
        } catch (\Exception $e) {
            Log::error("FileManager Upload Error: " . $e->getMessage(), [
                'stack' => $e->getTraceAsString(),
                'path' => $path,
                'entity_type' => $request->entity_type,
                'get_all' => $request->get_all,
                'file_name' => $file->getClientOriginalName()
            ]);
            throw new \Exception('Failed to store the uploaded file: ' . $e->getMessage(), 500);
        }
    }

    public function createFolder($path, $name, $request)
    {
        // Prepend base path based on request entity
        $basePath = $this->getBasePath($request);
        $completePath = $basePath . $path . '/' . $name;

        $fullPath = $this->getFullPath($completePath);
        $disk = $this->getDisk();

        if ($disk->exists($fullPath)) {
            throw new \Exception('Folder already exists', 422);
        }

        $disk->makeDirectory($fullPath);

        return [
            'name' => $name,
            'isDirectory' => true,
            'path' => rtrim($path, '/') . '/' . $name,
            'updatedAt' => date('c', $disk->lastModified($fullPath)),
        ];
    }

    public function renameItem($path, $newName, $request)
    {
        // Prepend base path based on request entity
        $basePath = $this->getBasePath($request);
        $completePath = $basePath . $path;

        $oldFullPath = $this->getFullPath($completePath);
        $disk = $this->getDisk();

        if (!$disk->exists($oldFullPath)) {
            throw new \Exception('Item not found', 404);
        }

        $directory = dirname($path);
        // Fix for dirname returning . or / when at root
        if ($directory === '.' || $directory === '\\')
            $directory = '';

        $newPath = $directory . '/' . $newName;
        // Clean up double slashes
        $newPath = str_replace('//', '/', $newPath);

        // Prepend base path to new path
        $newCompletePath = $basePath . $newPath;
        $newFullPath = $this->getFullPath($newCompletePath);

        if ($disk->exists($newFullPath)) {
            throw new \Exception('Item with new name already exists', 422);
        }

        $disk->move($oldFullPath, $newFullPath);

        // Determine if directory or file
        // Determine if directory or file
        try {
            $type = $disk->mimeType($newFullPath);
            $isDir = $type === 'directory' || $type === false;
        } catch (\Exception $e) {
            $isDir = true;
            $type = null;
        }

        $data = [
            'name' => $newName,
            'isDirectory' => $isDir,
            'path' => $newPath,
            'updatedAt' => date('c', $disk->lastModified($newFullPath)),
        ];

        if (!$isDir) {
            $data['size'] = $disk->size($newFullPath);
            $data['type'] = $type;
            $data['url'] = $this->getEncodedUrl($disk, $newFullPath);
        }

        return $data;
    }

    public function deleteItems(array $paths, $request)
    {
        $disk = $this->getDisk();

        // Get base path based on request entity
        $basePath = $this->getBasePath($request);

        foreach ($paths as $path) {
            $completePath = $basePath . $path;
            $fullPath = $this->getFullPath($completePath);
            if ($disk->exists($fullPath)) {

                // Determine if directory or file using robust check
                $isDir = false;
                try {
                    $type = $disk->mimeType($fullPath);
                    $isDir = $type === 'directory' || $type === false;
                } catch (\Exception $e) {
                    $isDir = true;
                }

                if ($isDir) {
                    $disk->deleteDirectory($fullPath);
                } else {
                    $disk->delete($fullPath);
                }
            }
        }
        return true;
    }

    public function pasteItems(array $files, $destinationPath, $operation, $request)
    {
        $disk = $this->getDisk();
        $results = [];

        // Get base path based on request entity
        $basePath = $this->getBasePath($request);

        foreach ($files as $file) {
            $sourcePath = $file['path'];
            $sourceCompletePath = $basePath . $sourcePath;
            $sourceFullPath = $this->getFullPath($sourceCompletePath);

            $name = basename($sourcePath);
            $destPath = rtrim($destinationPath, '/') . '/' . $name;
            $destCompletePath = $basePath . $destPath;
            $destFullPath = $this->getFullPath($destCompletePath);

            // Handle duplicate names
            if ($disk->exists($destFullPath)) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $newName = $nameWithoutExt . ' - Copy' . ($ext ? '.' . $ext : '');

                $destPath = rtrim($destinationPath, '/') . '/' . $newName;
                $destCompletePath = $basePath . $destPath;
                $destFullPath = $this->getFullPath($destCompletePath);
            }

            // Determine if source is directory
            $isDirectory = false;
            try {
                $mime = $disk->mimeType($sourceFullPath);
                if ($mime === 'directory' || $mime === false) {
                    $isDirectory = true;
                }
            } catch (\Exception $e) {
                $isDirectory = true;
            }

            // Perform operation
            if ($operation === 'copy') {
                if ($isDirectory) {
                    $this->copyDirectory($disk, $sourceFullPath, $destFullPath);
                } else {
                    $disk->copy($sourceFullPath, $destFullPath);
                }
            } elseif ($operation === 'move') {
                $disk->move($sourceFullPath, $destFullPath);
            }

            // Build complete item data with all metadata
            $itemData = [
                'name' => basename($destPath),
                'isDirectory' => $isDirectory,
                'path' => $destPath,
                'updatedAt' => date('c', $disk->lastModified($destFullPath)),
            ];

            // Add file-specific metadata
            if (!$isDirectory) {
                $itemData['size'] = $disk->size($destFullPath);
                $itemData['type'] = $disk->mimeType($destFullPath);
                $itemData['url'] = $this->getEncodedUrl($disk, $destFullPath);
            }

            $results[] = $itemData;
        }

        return $results;
    }

    private function copyDirectory($disk, $source, $destination)
    {
        $disk->makeDirectory($destination);

        $files = $disk->files($source);
        foreach ($files as $file) {
            $disk->copy($file, $destination . '/' . basename($file));
        }

        $directories = $disk->directories($source);
        foreach ($directories as $directory) {
            $this->copyDirectory($disk, $directory, $destination . '/' . basename($directory));
        }
    }

    /**
     * Download a file
     */
    public function downloadFile($path, $request)
    {
        // Prepend base path based on request entity
        $basePath = $this->getBasePath($request);
        $completePath = $basePath . $path;

        $fullPath = $this->getFullPath($completePath);
        $disk = $this->getDisk();

        if (!$disk->exists($fullPath)) {
            throw new \Exception('File not found', 404);
        }

        // Check if it's a directory
        try {
            $disk->mimeType($fullPath);
        } catch (\Exception $e) {
            throw new \Exception('Cannot download a directory', 400);
        }

        $filename = basename($path);
        $filePath = $disk->path($fullPath);

        return response()->download($filePath, $filename);
    }

    /**
     * Get folder tree recursively
     */
    public function getFolderTree($path, $request)
    {
        // Prepend base path based on request entity
        $basePath = $this->getBasePath($request);
        $completePath = $basePath . $path;

        $fullPath = $this->getFullPath($completePath);
        $disk = $this->getDisk();

        if (!$disk->exists($fullPath) && $fullPath !== '') {
            return [];
        }

        $directories = $disk->directories($fullPath);
        $tree = [];

        // Normalize the path
        $normalizedPath = '/' . trim($path, '/');
        if ($normalizedPath === '/') {
            $normalizedPath = '';
        }

        foreach ($directories as $directory) {
            $name = basename($directory);
            $dirPath = $normalizedPath . '/' . $name;

            $node = [
                'name' => $name,
                'isDirectory' => true,
                'path' => $dirPath,
                'updatedAt' => date('c', $disk->lastModified($directory)),
                'children' => $this->getFolderTree($dirPath, $request), // Recursive call
            ];

            $tree[] = $node;
        }

        return $tree;
    }
}