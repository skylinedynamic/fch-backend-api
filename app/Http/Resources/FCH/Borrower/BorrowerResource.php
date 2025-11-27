<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'full_name'           => $this->full_name,
            'business_name'       => $this->business_name,
            'business_email'      => $this->business_email,
            'business_phone'      => $this->business_phone,
            'company_name'        => optional($this->partner)->company_name, // Partner's company name
            'partner_id'          => $this->partner_id,
            'business_start_date' => $this->business_start_date ? $this->business_start_date : null,
        ];
    }
}
