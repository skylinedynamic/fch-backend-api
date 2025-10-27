<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\User\User;

/** @mixin User */
class PartnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_name' => trim($this->full_name) ?? null,
            'company_name' => $this->company->company_name ?? null,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'website' => $this->company->company_website ?? null,
            'state' => $this->state,
            'city' => $this->city,
            'country' => $this->country,
            'zip_code' => $this->postal_code,
            'tax_id' => $this->tax_identification_number,
            'status' => $this->company->status,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
        ];
    }
}