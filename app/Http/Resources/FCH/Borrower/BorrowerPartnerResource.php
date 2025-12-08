<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerPartnerResource extends JsonResource
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
            'first_name'          => $this->first_name,
            'last_name'           => $this->last_name,
            'full_name'           => $this->full_name,
            'email'               => $this->email,
            'phone_number'        => $this->phone_number,
            'business_name'       => $this->business_name,
            'business_start_date' => $this->business_start_date,
        ];
    }
}
