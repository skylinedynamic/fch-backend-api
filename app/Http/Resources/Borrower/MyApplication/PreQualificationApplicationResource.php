<?php

namespace App\Http\Resources\Borrower\MyApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreQualificationApplicationResource extends JsonResource
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
            'full_name' => $this->first_name . ' ' . $this->last_name,
            'funding_type' => $this->purpose_of_funding,
            'business_name' => $this->business_name,
            'industry' => $this->industry_type,
            'date' => $this->created_at->format('d M Y'),
            'requested_amount' => $this->funding_requested_amount,
            'status' => $this->status,
        ];
    }
}
