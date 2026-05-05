<?php

namespace App\Http\Resources\FCH\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'name' => $this->name,
            'desc' => $this->desc,
            'desc_list' => $this->desc_list,
            'logo' => $this->full_logo_url,
            'actual_path' => $this->logo,
            'is_recommended' => (bool) $this->is_recommended,
            'btn_label' => $this->btn_label,
            'link' => $this->link,
        ];
    }
}
