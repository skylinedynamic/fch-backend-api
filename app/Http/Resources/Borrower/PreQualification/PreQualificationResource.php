<?php

namespace App\Http\Resources\Borrower\PreQualification;

use App\Models\Borrower\Borrower;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreQualificationResource extends JsonResource
{
    public function toArray($request): array
    {
        $borrower = ($this->resource instanceof \Illuminate\Database\Eloquent\Model && $this->resource->relationLoaded('borrower'))
            ? $this->borrower 
            : (!empty($this->borrower_id) ? Borrower::find($this->borrower_id) : (auth()->check() && auth()->user()->entity_id ? Borrower::find(auth()->user()->entity_id) : null));

        $startDate = $this->business_start_date ?? $borrower?->business_start_date ?? null;
        $yearsInBusiness = !empty($startDate) 
            ? calculateTimeInBusiness($startDate) 
            : ($this->years_in_business ?? ($borrower?->years_in_business ? $borrower->years_in_business . ' Years' : null));

        return [
            'id' => $this->id,

            // Personal Info
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'business_website' => $this->business_website,
            'state_of_incorporation' => $this->state_of_incorporation,

            // Business Info
            'business_name' => $this->business_name,
            'industry_type' => $this->industry_type,
            'business_structure' => $this->business_structure,
            'years_in_business' => $yearsInBusiness,
            'monthly_revenue' => $this->monthly_revenue ? currencyFormat($this->monthly_revenue) : null,
            'annual_revenue' => $this->annual_revenue ? currencyFormat($this->annual_revenue) : null,

            // Financial Info
            'funding_requested_amount' => $this->funding_requested_amount ? currencyFormat($this->funding_requested_amount) : null,
            'funding_urgency' => $this->funding_urgency,
            'purpose_of_funding' => $this->purpose_of_funding,
            'purpose_note' => $this->purpose_note,
            'personal_credit_score' => $this->personal_credit_score,
            'estimated_business_credit_score' =>$this->estimated_business_credit_score,
            'existing_business_debt' => $this->existing_business_debt,
            'debt_balance' => $this->debt_balance ? currencyFormat($this->debt_balance) : null,

            //others
            'notes' => $this->notes,
            'status' => $this->status,
            
         ];
    }
}