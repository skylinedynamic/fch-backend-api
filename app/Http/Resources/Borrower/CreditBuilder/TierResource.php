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
            'code' => $this->code,
            'name' => $this->name,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'status' => $this->status,
            'order' => $this->order,
            'has_vendor' => $this->has_vendor,
            'task_to_complete' => $this->task_to_complete,
            'tasks' => TierTaskResource::collection($this->whenLoaded('tasks') ?? collect()),
        ];
    }
}
