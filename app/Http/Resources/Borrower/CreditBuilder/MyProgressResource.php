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
            'current_tier_estimated_completion_days' => $this->resource['current_tier_estimated_completion_days'] ?? 0,
            'current_tier_tasks_remaining' => $this->resource['tasks_remaining_count'] ?? 0,
            'current_tier_complete_task' => $this->resource['current_tier_completed_tasks'] ?? 0,
            'current_tier_complete_percentage' => $this->resource['task_complete_percentage'] ?? 0,
            'total_tasks_remaining' => $this->resource['total_tasks_remaining'] ?? 0,
            'total_complete_task' => $this->resource['total_completed_tasks'] ?? 0,
            'total_complete_percentage' => $this->resource['total_complete_percentage'] ?? 0,
            'tiers' => TierResource::collection($this->resource['tiers']),
        ];
    }
}
