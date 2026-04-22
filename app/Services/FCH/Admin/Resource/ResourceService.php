<?php

namespace App\Services\FCH\Admin\Resource;

use App\Models\Resource\Resource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ResourceService
{


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
            $path = $this->uploadFile($file);
            $resource->update(['file' => $path]);
        } elseif ($file && is_string($file)) {
            // If it's a string path from File Manager, just save it directly
            $path = ltrim($file, '/');
            $resource->update(['file' => $path]);
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
            $data['file'] = $this->uploadFile($data['file']);
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

    private function uploadFile(UploadedFile $file): string
    {
        // Store directly in resources folder
        return $file->store("resources", 'public');
    }

    private function deleteFile(string $path): void
    {
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
