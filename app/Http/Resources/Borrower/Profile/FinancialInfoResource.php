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
            'currency'         => $this->currency ?? null,
            'years_in_business'=> $this->years_in_business ?? null,
            'monthly_revenue'  => $this->monthly_revenue ?? null,
            'annual_revenue'   => $this->annual_revenue ?? null,
            'bank_accounts'    => $this->bankAccounts->map(fn ($b) => [
                'id'                => $b->id,
                'bank_name'         => $b->bank_name,
                'account_open_date' => $b->account_open_date
                    ? $b->account_open_date->format('Y-m-d')
                    : null,
            ])->values()->toArray(),
        ];
    }
}