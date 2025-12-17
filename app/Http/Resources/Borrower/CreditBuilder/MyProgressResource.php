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
            'estimated_completion_time' => $currentTier?->estimated_completion_time,
            'tasks_remaining_count' => $this->resource['tasks_remaining_count'] ?? 0,
            'task_complete_percentage' => $this->resource['task_complete_percentage'] ?? 0,
            'tiers' => TierResource::collection($this->resource['tiers']),
        ];
    }
}
