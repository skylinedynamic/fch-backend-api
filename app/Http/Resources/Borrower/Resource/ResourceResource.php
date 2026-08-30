<?php

namespace App\Http\Resources\Borrower\Resource;

use Illuminate\Http\Resources\Json\JsonResource;

class ResourceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'resource_type' => $this->resource_type,
            'file_url' => $this->file_url,
            'link' => $this->link,
            'status' => $this->status,
            'overview' => $this->overview,
            'impact' => $this->impact,
            'providers' => $this->providers->map(function($provider) {
                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'desc' => $provider->desc,
                    'is_recommended' => $provider->is_recommended,
                    'btn_label' => $provider->btn_label,
                    'link' => $provider->link,
                ];
            }),
            'created_at' => $this->created_at->format('F j, Y'),
        ];
    }
}
