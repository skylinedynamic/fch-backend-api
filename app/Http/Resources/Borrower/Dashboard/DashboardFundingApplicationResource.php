<?php

namespace App\Http\Resources\Borrower\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardFundingApplicationResource extends JsonResource
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
            'requested_amount' => $this->requested_amount,
            'entity_type' => $this->entity_type,
            'industry_type' => $this->industry_type,
            'status' => $this->status,
            'status_class' => $this->status_class,
            'purpose_of_funds' => $this->purpose_of_funds,
            'total_funded_amount' => $this->total_funded_amount,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,
            'formatted_created_at' => $this->created_at?->format('M d, Y'),
        ];
    }
}
