<?php

namespace App\Http\Resources\Borrower\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency ?? null,
            'years_in_business' => $this->years_in_business ?? null,
            'monthly_revenue' => $this->monthly_revenue ?? null,
            'annual_revenue' => $this->annual_revenue ?? null,
            'average_monthly_deposits' => $this->average_monthly_deposits ?? null,
            'monthly_deposit_count' => $this->monthly_deposit_count ?? null,
            'avg_bank_balance' => $this->avg_bank_balance ?? null,
            'current_business_debt' => $this->current_business_debt ?? null,
            'nsf_count' => $this->nsf_count ?? null,
            'negative_days' => $this->negative_days ?? null,
            'bank_accounts' => $this->bankAccounts->map(fn($b) => [
                'id' => $b->id,
                'bank_name' => $b->bank_name,
                'account_open_date' => $b->account_open_date
                    ? $b->account_open_date->format('Y-m-d')
                    : null,
            ])->values()->toArray(),
        ];
    }
}