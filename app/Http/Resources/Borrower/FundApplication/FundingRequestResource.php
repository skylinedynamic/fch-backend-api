<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'requested_amount' => $this->requested_amount ?? null,
            'purpose_of_funds' => $this->purpose_of_funds ?? null,
            'preferred_funding_type' => $this->preferred_funding_type ?? null,
            'timeframe_needed' => $this->timeframe_needed ?? null,
            'collateral_available' => $this->collateral_available ?? null,
        ];
    }
}
