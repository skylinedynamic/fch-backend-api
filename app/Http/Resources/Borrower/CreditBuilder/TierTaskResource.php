<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

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
        // Get status from the eager loaded relationship, default to pending
        $status = $this->borrowerTierTasks->first()?->status ?? 'pending';

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'status' => $status,
            'url' => $this->url,
            'button_title' => $this->button_title,
        ];
    }
}
