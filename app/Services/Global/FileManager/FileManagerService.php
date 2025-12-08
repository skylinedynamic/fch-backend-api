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

    private function getFullPath($section, $path)
    {
        // Sanitize path to prevent directory traversal
        $path = str_replace('..', '', $path);
        return trim($section . '/' . ltrim($path, '/'), '/');
    }

    private function getEncodedUrl($disk, $path)
    {
        $url = $disk->url($path);
        return str_replace(' ', '%20', $url);
    }

    public function listDocuments($section, $path = '/')
    {
        $fullPath = $this->getFullPath($section, $path);
        $disk = $this->getDisk();

        if (!$disk->exists($fullPath)) {
             if ($path === '/' && !$disk->exists($section)) {
                 $disk->makeDirectory($section);
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
                'path' => '/' . $name,
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
        
        return array_map(function ($item) use ($path) {
            $item['path'] = rtrim($path, '/') . $item['path'];
            return $item;
        }, $data);
    }

    public function uploadFile($section, $path, UploadedFile $file)
    {
        $fullPath = $this->getFullPath($section, $path);
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

    public function createFolder($section, $path, $name)
    {
        $fullPath = $this->getFullPath($section, $path . '/' . $name);
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

    public function renameItem($section, $path, $newName)
    {
        $oldFullPath = $this->getFullPath($section, $path);
        $disk = $this->getDisk();

        if (!$disk->exists($oldFullPath)) {
            throw new \Exception('Item not found', 404);
        }

        $directory = dirname($path);
        $newPath = $directory . '/' . $newName;
        $newFullPath = $this->getFullPath($section, $newPath);

        if ($disk->exists($newFullPath)) {
             throw new \Exception('Item with new name already exists', 422);
        }

        $disk->move($oldFullPath, $newFullPath);
        
        // Determine if directory or file
        // Fallback: try to get mime type. If it fails, it might be a directory.
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

    public function deleteItems($section, array $paths)
    {
        $disk = $this->getDisk();

        foreach ($paths as $path) {
            $fullPath = $this->getFullPath($section, $path);
            if ($disk->exists($fullPath)) {
                if (!$disk->delete($fullPath)) {
                    $disk->deleteDirectory($fullPath);
                }
            }
        }
        return true;
    }

    public function pasteItems($section, array $files, $destinationPath, $operation)
    {
        $disk = $this->getDisk();
        $results = [];

        foreach ($files as $file) {
            $sourcePath = $file['path'];
            $sourceFullPath = $this->getFullPath($section, $sourcePath);
            
            $name = basename($sourcePath);
            $destPath = rtrim($destinationPath, '/') . '/' . $name;
            $destFullPath = $this->getFullPath($section, $destPath);

            if ($disk->exists($destFullPath)) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $newName = $nameWithoutExt . ' - Copy.' . $ext;
                if ($ext == '') $newName = $nameWithoutExt . ' - Copy';
                
                $destPath = rtrim($destinationPath, '/') . '/' . $newName;
                $destFullPath = $this->getFullPath($section, $destPath);
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
