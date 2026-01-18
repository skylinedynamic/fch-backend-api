<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if(isset($this->borrower_id)){
            return [
                'id' => $this->id,
                'monthly_revenue' => $this->monthly_revenue ?? $this->borrower->monthly_revenue,
                'annual_revenue' => $this->annual_revenue ?? $this->borrower->annual_revenue,
                'business_bank_name' => $this->business_bank_name ?? null,
                'business_checking_start_date' => $this->business_checking_start_date ?? null,
                'self_reported_personal_credit_score' => $this->self_reported_personal_credit_score ?? $this->borrower->self_reported_personal_credit_score,
                'self_reported_business_credit_score' => $this->self_reported_business_credit_score ?? $this->borrower->self_reported_business_credit_score,
                'has_business_debt' => $this->has_business_debt ? true : false,
                'debt_balance' => $this->debt_balance ?? null,
            ];
        }
        else{
            return [
                'id' => null,
                'monthly_revenue' => $this->monthly_revenue ?? null,
                'annual_revenue' => $this->annual_revenue ?? null,
                'business_bank_name' => null,
                'business_checking_start_date' => null,
                'self_reported_personal_credit_score' => null,
                'self_reported_business_credit_score' => null,
                'has_business_debt' => false,
                'debt_balance' => null,
            ];
        } 
    }
}
