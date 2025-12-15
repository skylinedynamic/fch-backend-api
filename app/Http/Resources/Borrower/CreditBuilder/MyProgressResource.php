<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyProgressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTier = $this->resource['current_tier'];

        return [
            'id' => $currentTier?->id,
            'name' => $currentTier?->name,
            'title' => $currentTier?->title,
            'subtitle' => $currentTier?->subtitle,
            'description' => $currentTier?->description,
            'tiers' => TierResource::collection($this->resource['tiers']),
        ];
    }
}
