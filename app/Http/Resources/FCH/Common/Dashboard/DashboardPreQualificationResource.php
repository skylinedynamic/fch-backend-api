<?php

namespace App\Http\Resources\FCH\Common\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardPreQualificationResource extends JsonResource
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
            'borrower' => $this->first_name . ' ' . $this->last_name,
            'partner' => $this->partner?->full_name,
            'purpose_of_funding' => $this->purpose_of_funding,
            'industry_type' => $this->industry_type,
            'business_structure' => $this->business_structure,
            'funding_requested_amount' => $this->funding_requested_amount,
            'status' => $this->status,
            'status_class' => $this->status_class,
            'formatted_created_at' => $this->created_at?->format('M d, Y'),
        ];
    }
}
