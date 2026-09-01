<?php

namespace App\Http\Resources\FCH\Common\Application;

use App\Models\Borrower\Borrower;
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
        $borrower = ($this->resource instanceof \Illuminate\Database\Eloquent\Model && $this->resource->relationLoaded('borrower'))
            ? $this->borrower 
            : (!empty($this->borrower_id) ? Borrower::find($this->borrower_id) : (auth()->check() && auth()->user()->entity_id ? Borrower::find(auth()->user()->entity_id) : null));

        $startDate = $this->business_start_date ?? $borrower?->business_start_date ?? null;
        $yearsInBusiness = !empty($startDate) 
            ? calculateTimeInBusiness($startDate) 
            : ($this->years_in_business ?? ($borrower?->years_in_business ? $borrower->years_in_business . ' Years' : null));

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
            'monthly_revenue' => currencyFormat($this->monthly_revenue),
            'annual_revenue' => currencyFormat($this->annual_revenue),
            'years_in_business' => $yearsInBusiness,
            'funding_requested_amount' => currencyFormat($this->funding_requested_amount),
            'funding_urgency' => $this->funding_urgency,
            'purpose_of_funding' => $this->purpose_of_funding,
            'purpose_note' => $this->purpose_note,
            'personal_credit_score' => $this->personal_credit_score,
            'estimated_business_credit_score' => $this->estimated_business_credit_score,
            'existing_business_debt' => $this->existing_business_debt,
            'debt_balance' => currencyFormat($this->debt_balance),
            'notes' => $this->notes,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'status_class' => $this->status_class,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
