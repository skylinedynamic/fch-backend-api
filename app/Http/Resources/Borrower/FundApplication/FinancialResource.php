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
        $borrower = $this->borrower ?? ($this->resource instanceof \App\Models\Borrower\Borrower ? $this->resource : null);

        if (isset($this->borrower_id)) {
            $startDate = $this->business_checking_start_date ?? $borrower?->business_start_date;
            return [
                'id' => $this->id,
                'monthly_revenue' => $this->monthly_revenue ?? $borrower?->monthly_revenue ?? null,
                'annual_revenue' => $this->annual_revenue ?? $borrower?->annual_revenue ?? null,
                'business_bank_name' => $this->business_bank_name ?? null,
                'business_checking_start_date' => toDateOnly($startDate),
                'self_reported_personal_credit_score' => $this->self_reported_personal_credit_score ?? ($borrower?->fico_score ? (string)$borrower->fico_score : null),
                'self_reported_business_credit_score' => $this->self_reported_business_credit_score ?? ($borrower?->paydex_score ? (string)$borrower->paydex_score : null),
                'has_business_debt' => $this->has_business_debt ? true : ($borrower && $borrower->current_business_debt > 0 ? true : false),
                'debt_balance' => $this->debt_balance ?? ($borrower?->current_business_debt ?? null),
                'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
                'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
            ];
        } else {
            $startDate = $borrower?->business_start_date ?? $this->business_checking_start_date;
            return [
                'id' => null,
                'monthly_revenue' => $borrower?->monthly_revenue ?? $this->monthly_revenue ?? null,
                'annual_revenue' => $borrower?->annual_revenue ?? $this->annual_revenue ?? null,
                'business_bank_name' => null,
                'business_checking_start_date' => toDateOnly($startDate),
                'self_reported_personal_credit_score' => $borrower?->fico_score ? (string)$borrower->fico_score : null,
                'self_reported_business_credit_score' => $borrower?->paydex_score ? (string)$borrower->paydex_score : null,
                'has_business_debt' => $borrower && $borrower->current_business_debt > 0 ? true : false,
                'debt_balance' => $borrower?->current_business_debt ?? null,
                'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
                'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
            ];
        }
    }
}
