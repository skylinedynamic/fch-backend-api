<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use App\Http\Resources\FCH\CreditBuilder\CreditBuilderVendorResource;
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
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'status' => $this->status,
            'order' => $this->order,
            'has_vendor' => $this->has_vendor,
            'tasks' => TierTaskResource::collection($this->whenLoaded('tasks')),
            'vendors' => CreditBuilderVendorResource::collection($this->whenLoaded('vendors')),
        ];
    }
}
