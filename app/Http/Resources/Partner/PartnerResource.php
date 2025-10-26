<?php

namespace App\Http\Resources\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Company\Company;

/** @mixin Company */
class PartnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_name' => $this->additional_data['partner_name'] ?? null,
            'company_name' => $this->company_name,
            'email' => $this->company_email,
            'phone_number' => $this->company_phone,
            'website' => $this->company_website,
            'state' => $this->state,
            'city' => $this->city,
            'country' => $this->country,
            'zip_code' => $this->postal_code,
            'tax_id' => $this->tax_identification_number,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}