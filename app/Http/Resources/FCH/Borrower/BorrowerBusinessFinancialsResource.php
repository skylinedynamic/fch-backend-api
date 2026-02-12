<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerBusinessFinancialsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'currency'                  => $this->currency,
            'monthly_revenue'           => formatComma($this->monthly_revenue),
            'annual_revenue'            => formatComma($this->annual_revenue),
            'avg_bank_balance'          => formatComma($this->avg_bank_balance),
            'profit_margin'             => $this->profit_margin,
            'current_business_debt'     => formatComma($this->current_business_debt),
            'fico_score'                => $this->fico_score,
            'paydex_score'              => $this->paydex_score,
            'years_in_business'         => $this->years_in_business,
            'number_of_employees'       => $this->number_of_employees,
            'nsf_days'                  => $this->nsf_days,
            'funding_urgency'           => $this->funding_urgency,
            'status'                    => $this->status,
        ];
    }
}
