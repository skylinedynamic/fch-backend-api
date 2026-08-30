<?php

namespace App\Services\FCH\Admin\Resource;

use App\Models\Resource\Resource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ResourceService
{


    public function getByType(string $type)
    {
        return Resource::with([
            'providers' => function ($q) {
                $q->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
            }
        ])->where('resource_type', $type)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    public function create(array $data)
    {
        $file = $data['file'] ?? null;
        $providers = $data['providers'] ?? null;
        unset($data['file'], $data['providers']);

        $resource = Resource::create($data);

        if ($file && $file instanceof UploadedFile) {
            $path = $this->uploadFile($file);
            $resource->update(['file' => $path]);
        } elseif ($file && is_string($file)) {
            $path = ltrim($file, '/');
            $resource->update(['file' => $path]);
        }

        if ($providers && is_array($providers)) {
            $this->syncProviders($resource, $providers);
        }

        return $resource;
    }

    public function update(int $id, array $data)
    {
        $resource = Resource::findOrFail($id);
        $providers = $data['providers'] ?? null;
        unset($data['providers']);

        if (isset($data['file'])) {
            if ($data['file'] instanceof UploadedFile) {
                if ($resource->file) {
                    $this->deleteFile($resource->file);
                }
                $data['file'] = $this->uploadFile($data['file']);
            } elseif (is_string($data['file'])) {
                $data['file'] = ltrim($data['file'], '/');
            }
        }

        $resource->update($data);

        if ($providers !== null && is_array($providers)) {
            $this->syncProviders($resource, $providers);
        }

        return $resource;
    }

    public function delete(int $id)
    {
        $resource = Resource::findOrFail($id);

        // Remove associated providers
        $resource->providers()->delete();

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

    private function syncProviders(Resource $resource, array $providers): void
    {
        // For simplicity, we delete and re-create providers on sync
        // Alternatively, we could do a more complex diff-based update
        $resource->providers()->delete();

        foreach ($providers as $providerData) {
            $resource->providers()->create([
                'name' => $providerData['name'] ?? '',
                'desc' => $providerData['desc'] ?? '',
                'desc_list' => $providerData['desc_list'] ?? [],
                'logo' => $providerData['logo'] ?? '',
                'is_recommended' => filter_var($providerData['is_recommended'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'btn_label' => $providerData['btn_label'] ?? '',
                'link' => $providerData['link'] ?? '',
                'sort_order' => (int) ($providerData['sort_order'] ?? 0),
            ]);
        }
    }
}
