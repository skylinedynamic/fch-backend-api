<?php

namespace App\Http\Resources\FCH\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetailsResource extends JsonResource
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
            'is_partner' => $this->is_partner,
            'is_borrower' => $this->is_borrower,
            'overview' => $this->overview,
            'impact' => $this->impact,
            'overview_text' => $this->overview_text,
            'impact_text' => $this->impact_text,
            'sort_order' => $this->sort_order,
            'providers' => ProviderResource::collection($this->whenLoaded('providers') ?? collect()),
            'created_at' => $this->created_at->format('F j, Y'),
            'updated_at' => $this->updated_at->format('F j, Y'),
        ];
    }
}
