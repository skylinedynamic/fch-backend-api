<?php

namespace App\Http\Resources\Borrower\MyApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingApplicationResource extends JsonResource
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
            'status' => $this->status,
            'full_name' => $this->full_name,
            'industry_type' => $this->industry_type,
            'entity_type' => $this->entity_type,
            'requested_amount' => $this->requested_amount,
            'purpose_of_funds' => $this->purpose_of_funds,
            'date' => $this->created_at->format('d M Y'),
        ];
    }
}
