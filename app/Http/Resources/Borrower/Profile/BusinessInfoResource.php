<?php

namespace App\Http\Resources\Borrower\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'business_name' => $this->company_name ?? null,
            'business_ein' => $this->company_ein ?? null,
            'business_phone' => $this->company_phone ?? null,
            'business_address' => $this->address_line_1 ?? null,
            'city' => $this->city ?? null,
            'state' => $this->state ?? null,
            'zip_code' => $this->postal_code ?? null,
            'entity_type' => $this->company_entity_type ?? null,
            'business_website' => $this->company_website ?? null,
            'industry_type' => $this->company_type ?? null,
            'years_in_business' => $this->years_in_business ?? null,
        ];
    }
}