<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditAccountResource extends JsonResource
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
            'card_name' => $this->card_name,
            'issuer' => $this->issuer,
            'credit_category' => $this->credit_category,
            'typical_personal_fico_min' => $this->typical_personal_fico_min,
            'time_in_business_min_months' => $this->time_in_business_min_months,
            'image_url' => $this->image_url,
            'full_image_url' => $this->full_image_url,
            'reference_url' => $this->reference_url,
            'status' => $this->status ?? 'Active',
            'personal_guarantee' => $this->personal_guarantee,
            'business_credit_profile' => $this->business_credit_profile,
            'final_approval' => $this->final_approval,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
