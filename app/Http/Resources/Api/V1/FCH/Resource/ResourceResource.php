<?php

namespace App\Http\Resources\Api\V1\FCH\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_type' => $this->resource_type,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'status' => $this->status,
            'file' => $this->file,
            'file_url' => $this->file_url,
            'link' => $this->link,
            'icon' => $this->icon,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
