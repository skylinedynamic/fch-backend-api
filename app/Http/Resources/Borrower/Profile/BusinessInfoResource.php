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
            'company_name' => $this->company_name ?? null,
            'company_ein' => $this->company_ein ?? null,
            'company_phone' => $this->company_phone ?? null,
            'address_line_1' => $this->address_line_1 ?? null,
            'city' => $this->city ?? null,
            'state' => $this->state ?? null,
            'postal_code' => $this->postal_code ?? null,
            'company_entity_type' => $this->company_entity_type ?? null,
            'company_website' => $this->company_website ?? null,
            'company_type' => $this->company_type ?? null,
        ];
    }
}