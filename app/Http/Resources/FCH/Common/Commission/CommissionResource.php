<?php

namespace App\Http\Resources\FCH\Common\Commission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionResource extends JsonResource
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
            'borrower' => $this->fundingApplication->full_name,
            'partner' => $this->partner->full_name,
            'funding_application_id' => $this->funding_application_id,
            'total_commission' => $this->total_commission,
            'admin_commission' => $this->admin_commission,
            'partner_commission' => $this->partner_commission,
            'total_commission_amount' => $this->total_commission_amount,
            'admin_commission_amount' => $this->admin_commission_amount,
            'partner_commission_amount' => $this->partner_commission_amount,
            'commission_status' => $this->commission_status,
            'requested_amount' => $this->fundingApplication->requested_amount,
            'funded_amount' => $this->fundingApplication->funded_amount,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,
            'note' => $this->note,
            'date' => date('Y/m/d', strtotime($this->created_at)),
        ];
    }
}
