<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'full_name' => $this->full_name ?? null,
            'business_name' => $this->business_name ?? null,
            'business_email' => $this->business_email ?? null,
            'business_phone_no' => $this->business_phone ?? null,
            'business_country' => $this->business_country ?? null,
            'business_country_code' => $this->business_country_code ?? null,
            'business_address' => $this->business_address ?? null,
            'business_city' => $this->business_city ?? null,
            'business_state' => $this->business_state ?? null,
            'business_postal_code' => $this->business_postal_code ?? null,
            'industry_type' => $this->business_industry_type ?? null,
            'entity_type' => $this->business_entity_type ?? null,
            'years_in_business' => $this->years_in_business ?? null,
            'number_of_employees' => $this->number_of_employees ?? null,
        ];
    }
}
