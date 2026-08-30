<?php

namespace App\Http\Resources\Borrower\PreQualification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerDetailsProfile extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Personal Info
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'home_address' => $this->home_address,
            'home_phone' => $this->home_phone,
            'date_of_birth' => $this->date_of_birth,
            'last_4_ssn' => $this->last_4_ssn,

            // Business Info
            'business_name' => $this->business_name,
            'business_ein' => $this->business_ein,
            'business_phone' => $this->business_phone,
            'business_address' => $this->business_address,
            'business_city' => $this->business_city,
            'business_state' => $this->business_state,
            'business_postal_code' => $this->business_postal_code,
            'business_email' => $this->business_email,
            'business_website' => $this->business_website,
            'business_entity_type' => $this->business_entity_type,
            'business_industry_type' => $this->business_industry_type,
            'business_description' => $this->business_description,
            'business_logo' => $this->business_logo,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'duns_number' => $this->duns_number,
            'state_file_number' => $this->state_file_number,
            'business_start_date' => $this->business_start_date,

            // Financial Info
            'currency' => $this->currency,
            'years_in_business' => $this->business_start_date ? calculateTimeInBusiness($this->business_start_date) : null,
            'monthly_revenue' => $this->monthly_revenue,
            'annual_revenue' => $this->annual_revenue,
        ];
    }
}
