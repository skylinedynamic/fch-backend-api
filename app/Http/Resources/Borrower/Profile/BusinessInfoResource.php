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
            'business_name' => $this->business_name ?? null,
            'business_ein' => $this->business_ein ?? null,
            'business_phone' => $this->business_phone ?? null,
            'business_address' => $this->business_address ?? null,
            'business_email' => $this->business_email ?? null,
            'duns_number' => $this->duns_number ?? null,
            'country' => $this->country ?? null,
            'country_code' => $this->country_code ?? null,
            'business_state' => $this->business_state ?? null,
            'business_city' => $this->business_city ?? null,
            'business_postal_code' => $this->business_postal_code ?? null,
            'business_website' => $this->business_website ?? null,
            'business_entity_type' => $this->business_entity_type ?? null,
            'business_industry_type' => $this->business_industry_type ?? null,
        ];
    }
}