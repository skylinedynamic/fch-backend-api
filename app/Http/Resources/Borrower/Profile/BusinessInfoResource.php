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
    
            'business_no' => $this->business_no ?? null,
            'business_name' => $this->business_name ?? null,
            'business_ein' => $this->business_ein ?? null,
            'business_phone' => $this->business_phone ?? null,
            'business_website' => $this->business_website ?? null,
            'city' => $this->city ?? null,
            'state' => $this->state ?? null,
            'zip_code' => $this->postal_code ?? null,
            'country' => $this->country ?? null,
            'business_address' => $this->address_line_1 ?? null,
            // 'address_line_2' => $this->address_line_2 ?? null,
            'entity_type' => $this->business_entity_type ?? null,
            'industry_type' => $this->business_type ?? null,
            // 'business_registration_number' => $this->business_registration_number ?? null,
            // 'tax_identification_number' => $this->tax_identification_number ?? null,
            // 'state_file_number' => $this->state_file_number ?? null,
            // 'duns_number' => $this->duns_number ?? null,
            // 'status' => $this->status ?? null,
            // 'assign_id' => $this->assign_id ?? null,
            // 'business_description' => $this->business_description ?? null,
            // 'business_logo' => $this->business_logo ?? null,
            // 'notes' => $this->notes ?? null,
            // 'business_start_date' => $this->business_start_date ?? null,
            // 'years_in_business' => $this->years_in_business ?? null,
            // 'currency' => $this->currency ?? null,
            // 'monthly_revenue' => is_null($this->monthly_revenue) ? null : (float) $this->monthly_revenue,
            // 'annual_revenue' => is_null($this->annual_revenue) ? null : (float) $this->annual_revenue,
            // 'additional_data' => (function () {
            //     if (is_null($this->additional_data)) {
            //         return null;
            //     }
            //     if (is_array($this->additional_data)) {
            //         return $this->additional_data;
            //     }
            //     $decoded = json_decode($this->additional_data, true);
            //     return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->additional_data;
            // })(),
        ];
    }
}