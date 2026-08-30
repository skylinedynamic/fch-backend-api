<?php

namespace App\Http\Resources\Global\FileManager;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileManagerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'],
            'isDirectory' => $this['isDirectory'],
            'path' => $this['path'],
            'updatedAt' => $this['updatedAt'],
            'size' => $this['size'] ?? 0,
            'type' => $this['type'] ?? 'folder',
            'url' => !empty($this['url']) ? asset($this['url']) : null,
        ];
    }
}
