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
        return [
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
