<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierTaskResource extends JsonResource
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
            'tier_id' => $this->tier_id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'logo' => $this->logo ? asset('/' . encodeUrl($this->logo)) : null,
            'description' => $this->description,
            'url' => $this->url,
            'related_api_id' => $this->related_api_id,
            'button_title' => $this->button_title,
            'status' => $this->status,
            'order' => $this->order,
            'manual_verification' => $this->manual_verification,
            'estimated_completion_days' => $this->estimated_completion_days,
            'is_mandatory' => $this->is_mandatory,
            'tier_name' => $this->tier ? $this->tier->name : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
