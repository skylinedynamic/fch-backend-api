<?php

namespace App\Http\Resources\FCH\Common\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandingConfigurationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // If the resource is an array (from update), return it directly
        if (is_array($this->resource)) {
            return $this->resource;
        }

        return parent::toArray($request);
    }
}
