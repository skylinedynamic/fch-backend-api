<?php

namespace App\Services\Global\FileManager;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

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
        $url = $disk->url($path);
        return str_replace(' ', '%20', $url);
    }

    public function listDocuments($path = '/')
    {
        $fullPath = $this->getFullPath($path);
        $disk = $this->getDisk();

        if (!$disk->exists($fullPath)) {
             // If path is root and doesn't exist, we don't need to create it as it's the root of the disk
             // But if it's a subfolder that doesn't exist, we might want to handle it or return empty
             // For now, let's just proceed, files() and directories() will return empty arrays if path doesn't exist (or throw error depending on driver)
             // Actually, Storage::files throws if directory doesn't exist.
             if ($fullPath !== '' && !$disk->exists($fullPath)) {
                  // Optional: Create if not exists or return empty
                  // $disk->makeDirectory($fullPath);
                  return []; 
             }
        }
        
        $files = $disk->files($fullPath);
        $directories = $disk->directories($fullPath);

        $data = [];

        foreach ($directories as $directory) {
            $name = basename($directory);
            $data[] = [
                'name' => $name,
                'isDirectory' => true,
                'path' => '/' . $name, // This might need adjustment if we want relative path from root
                'updatedAt' => date('c', $disk->lastModified($directory)),
            ];
        }

        foreach ($files as $file) {
            $name = basename($file);
            $data[] = [
                'name' => $name,
                'isDirectory' => false,
                'path' => '/' . $name,
                'url' => $this->getEncodedUrl($disk, $file),
                'updatedAt' => date('c', $disk->lastModified($file)),
                'size' => $disk->size($file),
                'type' => $disk->mimeType($file),
            ];
        }
        
        // Adjust paths to be relative to the requested path if needed, or just absolute from root
        // The previous logic was appending to $path. 
        // If $path is /folder, and item is /folder/sub, basename is sub. 
        // We want the returned path to be /folder/sub.
        
        return array_map(function ($item) use ($path) {
            // Ensure path starts with /
            $basePath = '/' . trim($path, '/');
            if ($basePath === '/') $basePath = '';
            
            $item['path'] = $basePath . $item['path'];
            return $item;
        }, $data);
    }

    public function uploadFile($path, UploadedFile $file)
    {
        $fullPath = $this->getFullPath($path);
        $disk = $this->getDisk();

        $filename = $file->getClientOriginalName();
        $pathResult = $file->storeAs($fullPath, $filename, 'public');

        return [
            'name' => $filename,
            'isDirectory' => false,
            'path' => rtrim($path, '/') . '/' . $filename,
            'url' => $this->getEncodedUrl($disk, $pathResult),
            'updatedAt' => date('c', $disk->lastModified($pathResult)),
            'size' => $disk->size($pathResult),
            'type' => $disk->mimeType($pathResult),
        ];
    }

    public function createFolder($path, $name)
    {
        $fullPath = $this->getFullPath($path . '/' . $name);
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

    public function renameItem($path, $newName)
    {
        $oldFullPath = $this->getFullPath($path);
        $disk = $this->getDisk();

        if (!$disk->exists($oldFullPath)) {
            throw new \Exception('Item not found', 404);
        }

        $directory = dirname($path);
        // Fix for dirname returning . or / when at root
        if ($directory === '.' || $directory === '\\') $directory = '';
        
        $newPath = $directory . '/' . $newName;
        // Clean up double slashes
        $newPath = str_replace('//', '/', $newPath);
        
        $newFullPath = $this->getFullPath($newPath);

        if ($disk->exists($newFullPath)) {
             throw new \Exception('Item with new name already exists', 422);
        }

        $disk->move($oldFullPath, $newFullPath);
        
        // Determine if directory or file
        try {
            $type = $disk->mimeType($newFullPath);
            $isDir = false;
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

    public function deleteItems(array $paths)
    {
        $disk = $this->getDisk();

        foreach ($paths as $path) {
            $fullPath = $this->getFullPath($path);
            if ($disk->exists($fullPath)) {
                if (!$disk->delete($fullPath)) {
                    $disk->deleteDirectory($fullPath);
                }
            }
        }
        return true;
    }

    public function pasteItems(array $files, $destinationPath, $operation)
    {
        $disk = $this->getDisk();
        $results = [];

        foreach ($files as $file) {
            $sourcePath = $file['path'];
            $sourceFullPath = $this->getFullPath($sourcePath);
            
            $name = basename($sourcePath);
            $destPath = rtrim($destinationPath, '/') . '/' . $name;
            $destFullPath = $this->getFullPath($destPath);

            if ($disk->exists($destFullPath)) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $newName = $nameWithoutExt . ' - Copy.' . $ext;
                if ($ext == '') $newName = $nameWithoutExt . ' - Copy';
                
                $destPath = rtrim($destinationPath, '/') . '/' . $newName;
                $destFullPath = $this->getFullPath($destPath);
            }

            if ($operation === 'copy') {
                try {
                    $disk->mimeType($sourceFullPath);
                    $isDir = false;
                } catch (\Exception $e) {
                    $isDir = true;
                }

                if ($isDir) {
                    $this->copyDirectory($disk, $sourceFullPath, $destFullPath);
                } else {
                    $disk->copy($sourceFullPath, $destFullPath);
                }
                
            } elseif ($operation === 'move') {
                 $disk->move($sourceFullPath, $destFullPath);
            }
            
            $itemData = [
                'name' => basename($destPath),
                'path' => $destPath,
            ];

            // Check if it's a file to add URL
            $isDestDir = false;
            try {
                $disk->mimeType($destFullPath);
            } catch (\Exception $e) {
                $isDestDir = true;
            }

            if (!$isDestDir) {
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
}
