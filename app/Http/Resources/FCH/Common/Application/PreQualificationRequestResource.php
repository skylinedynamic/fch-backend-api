<?php

namespace App\Http\Resources\FCH\Common\Application;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreQualificationRequestResource extends JsonResource
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
            'borrower_id' => $this->borrower_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'business_website' => $this->business_website,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'state_of_incorporation' => $this->state_of_incorporation,
            'business_name' => $this->business_name,
            'industry_type' => $this->industry_type,
            'business_structure' => $this->business_structure,
            'monthly_revenue' => $this->monthly_revenue,
            'annual_revenue' => $this->annual_revenue,
            'years_in_business' => $this->years_in_business,
            'funding_requested_amount' => $this->funding_requested_amount,
            'funding_urgency' => $this->funding_urgency,
            'purpose_of_funding' => $this->purpose_of_funding,
            'personal_credit_score' => $this->personal_credit_score,
            'estimated_business_credit_score' => $this->estimated_business_credit_score,
            'existing_business_debt' => $this->existing_business_debt,
            'debt_balance' => $this->debt_balance,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'status_class' => $this->status_class,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
