<?php

namespace App\Services\FCH\Admin\Resource;

use App\Models\Resource\Resource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ResourceService
{
    public function getAllGrouped()
    {
        $resources = Resource::all();
        $grouped = [];
        
        foreach (Resource::getTypes() as $type) {
            $grouped[$type] = [];
        }

        foreach ($resources as $resource) {
            $grouped[$resource->resource_type][] = $resource;
        }

        return $grouped;
    }

    public function getByType(string $type)
    {
        return Resource::where('resource_type', $type)->get();
    }

    public function create(array $data)
    {
        $file = $data['file'] ?? null;
        unset($data['file']); // Remove file temporarily to save record first

        $resource = Resource::create($data);

        if ($file && $file instanceof UploadedFile) {
            $path = $this->uploadFile($file, $resource->id);
            $resource->update(['file' => $path]);
        } elseif ($file && is_string($file)) {
            $path = ltrim($file, '/');
            $tempPrefix = "resources/0/";
            
            // If selected from the temporary '0' folder, move to permanent ID folder
            if (str_starts_with($path, $tempPrefix)) {
                $filename = basename($path);
                $newPath = "resources/{$resource->id}/{$filename}";
                
                if (Storage::disk('public')->exists($path)) {
                    // Create directory if not exists
                    Storage::disk('public')->makeDirectory("resources/{$resource->id}");
                    Storage::disk('public')->move($path, $newPath);
                    $resource->update(['file' => $newPath]);
                } else {
                    $resource->update(['file' => $newPath]);
                }
            } else {
                // Ensure it has the correct prefix if it was selected relative to the entity root in File Manager
                $filename = basename($path);
                $accuratePath = "resources/{$resource->id}/{$filename}";
                if (Storage::disk('public')->exists($accuratePath)) {
                    $resource->update(['file' => $accuratePath]);
                } else {
                    $resource->update(['file' => $path]);
                }
            }
        }

        return $resource;
    }

    public function update(int $id, array $data)
    {
        $resource = Resource::findOrFail($id);

        if (isset($data['file']) && $data['file'] instanceof UploadedFile) {
            if ($resource->file) {
                $this->deleteFile($resource->file);
            }
            $data['file'] = $this->uploadFile($data['file'], $resource->id);
        }

        $resource->update($data);
        return $resource;
    }

    public function delete(int $id)
    {
        $resource = Resource::findOrFail($id);
        
        // Soft deleting record
        return $resource->delete();
    }

    private function uploadFile(UploadedFile $file, $resourceId): string
    {
        // Store in ID-specific folder
        return $file->store("resources/{$resourceId}", 'public');
    }

    private function deleteFile(string $path): void
    {
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
