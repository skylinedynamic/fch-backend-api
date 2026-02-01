<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerBusinessFinancialsResource extends JsonResource
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
            // Business
            'business_name'             => $this->business_name,
            'business_ein'              => $this->business_ein,
            'business_phone'            => $this->business_phone,
            'business_address'          => $this->business_address,
            'business_city'             => $this->business_city,
            'business_state'            => $this->business_state,
            'business_postal_code'      => $this->business_postal_code,
            'business_email'            => $this->business_email,
            'business_website'          => $this->business_website,
            'business_entity_type'      => $this->business_entity_type,
            'business_industry_type'    => $this->business_industry_type,
            'business_description'      => $this->business_description,
            'business_logo'             => $this->business_logo ? url($this->business_logo) : null,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'duns_number'               => $this->duns_number,
            'state_file_number'         => $this->state_file_number,
            'business_start_date'       => $this->business_start_date,

            // Financial
            'currency'                  => $this->currency,
            'years_in_business'         => $this->years_in_business,
            'monthly_revenue'           => $this->monthly_revenue,
            'annual_revenue'            => $this->annual_revenue,
            'avg_bank_balance'          => $this->avg_bank_balance,
            'profit_margin'             => $this->profit_margin,
            'current_business_debt'     => $this->current_business_debt,
            'fico_score'                => $this->fico_score,
            'paydex_score'              => $this->paydex_score,
            'number_of_employees'       => $this->number_of_employees,
            'nsf_days'                  => $this->nsf_days,
            'funding_urgency'           => $this->funding_urgency,
            
            'status'        => $this->status,
        ];
    }
}
