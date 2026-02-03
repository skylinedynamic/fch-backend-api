<?php

namespace App\Http\Resources\FCH\Common\Commission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingApplicationCommissionResource extends JsonResource
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
            'admin_commission' => $this->admin_commission,
            'admin_commission_amount' => $this->admin_commission_amount,
            'partner_commission' => $this->partner_commission,
            'partner_commission_amount' => $this->partner_commission_amount,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,
        ];
    }
}
