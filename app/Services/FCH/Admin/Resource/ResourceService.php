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
        if (isset($data['file']) && $data['file'] instanceof UploadedFile) {
            $data['file'] = $this->uploadFile($data['file']);
        }

        return Resource::create($data);
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
        
        // Use soft deletes, so we usually don't delete the physical file right away
        // if ($resource->file) {
        //     $this->deleteFile($resource->file);
        // }

        return $resource->delete();
    }

    private function uploadFile(UploadedFile $file): string
    {
        return $file->store('resources', 'public');
    }

    private function deleteFile(string $path): void
    {
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
