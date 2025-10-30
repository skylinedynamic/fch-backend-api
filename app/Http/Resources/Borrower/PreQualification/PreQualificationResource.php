<?php

namespace App\Http\Resources\Borrower\PreQualification;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class PreQualificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'business_website' => $this->business_website,
            'email' => $this->email,
            'phone' => $this->phone,
            'state_of_incorporation' => $this->state_of_incorporation,
            'business_name' => $this->business_name,
            'industry' => $this->industry,
            'business_structure' => $this->business_structure,
            'monthly_revenue' => $this->monthly_revenue,
            'annual_revenue' => $this->annual_revenue,
            'time_in_business' => $this->time_in_business,
            'funding_requested_amount' => $this->funding_requested_amount,
            'funding_urgency' => $this->funding_urgency,
            'purpose_of_funding' => $this->purpose_of_funding,
            'personal_credit_score' => $this->personal_credit_score,
            'estimated_business_credit_score' => $this->estimated_business_credit_score,
            'existing_business_debt' => (bool) $this->existing_business_debt,
            'debt_balance' => $this->debt_balance,
            'notes' => $this->notes,
            'status' => $this->status,
        ];
    }
}