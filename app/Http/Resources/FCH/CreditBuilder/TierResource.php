<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierResource extends JsonResource
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
            'title' => $this->title,
            'code' => $this->code, 
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'has_vendor' => $this->has_vendor,
            'status' => $this->status,
            'order' => $this->order,
            'estimated_completion_days' => $this->estimated_completion_days,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
