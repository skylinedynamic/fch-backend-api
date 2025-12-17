<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

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
            'estimated_completion_time' => $this->estimated_completion_time,
            'status' => $this->status,
            'order' => $this->order,
            'tasks' => TierTaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
