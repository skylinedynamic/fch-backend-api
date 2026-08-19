<?php

namespace App\Http\Resources\FCH\Partner\FundApplication;

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
        $borrower = $this->borrower ?? ($this->resource instanceof \App\Models\Borrower\Borrower ? $this->resource : null);

        $fullName = $this->full_name 
            ?? ($borrower ? trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) : null);

        $businessName = $this->business_name ?? $borrower?->business_name ?? null;
        $businessEmail = $this->business_email ?? $borrower?->business_email ?? $borrower?->email ?? null;
        $businessPhone = $this->business_phone_no ?? $this->business_phone ?? $borrower?->business_phone ?? $borrower?->phone_number ?? null;
        $businessCountry = $this->business_country ?? $borrower?->business_country ?? $borrower?->country ?? null;
        $businessCountryCode = $this->business_country_code ?? $borrower?->business_country_code ?? $borrower?->country_code ?? null;
        $businessAddress = $this->business_address ?? $borrower?->business_address ?? null;
        $businessCity = $this->business_city ?? $borrower?->business_city ?? null;
        $businessState = $this->business_state ?? $borrower?->business_state ?? null;
        $businessPostalCode = $this->business_postal_code ?? $borrower?->business_postal_code ?? null;
        $industryType = $this->industry_type ?? $this->business_industry_type ?? $borrower?->business_industry_type ?? null;
        $entityType = $this->entity_type ?? $this->business_entity_type ?? $borrower?->business_entity_type ?? null;
        $employees = $this->number_of_employees ?? $borrower?->number_of_employees ?? null;

        $startDate = $this->business_start_date ?? $borrower?->business_start_date ?? null;
        $yearsInBusiness = !empty($startDate) 
            ? calculateTimeInBusiness($startDate) 
            : ($this->years_in_business ?? ($borrower?->years_in_business ? $borrower->years_in_business . ' Years' : null));

        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'full_name' => $fullName,
            'business_name' => $businessName,
            'business_email' => $businessEmail,
            'business_phone_no' => $businessPhone,
            'business_country' => $businessCountry,
            'business_country_code' => $businessCountryCode,
            'business_address' => $businessAddress,
            'business_city' => $businessCity,
            'business_state' => $businessState,
            'business_postal_code' => $businessPostalCode,
            'industry_type' => $industryType,
            'entity_type' => $entityType,
            'years_in_business' => $yearsInBusiness,
            'number_of_employees' => $employees,
            'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
            'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
        ];
    }
}

