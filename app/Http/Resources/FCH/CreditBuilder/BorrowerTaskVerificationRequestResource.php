<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerTaskVerificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'partner_id' => $this->partner_id,
            'partner_name' => $this->partner?->full_name,
            'tier_id' => $this->tier_id,
            'tier_name' => $this->tier?->name,
            'pending_count' => $this->pending_count,
            'request_date' => $this->request_date ? (Carbon::parse($this->request_date)->format('F d, Y h:i A')) : null,
        ];
    }
}
