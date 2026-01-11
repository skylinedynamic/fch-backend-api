<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditBuilderVendorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'requirements' => $this->requirements,
            'benefits' => $this->benefits,
            'report_to' => $this->report_to,
            'tier_id' => $this->tier_id,
            'status' => $this->status,
            'description' => $this->description,
            'general_url' => $this->general_url,
            'affiliate_url' => $this->affiliate_url,
        ];
    }
}
