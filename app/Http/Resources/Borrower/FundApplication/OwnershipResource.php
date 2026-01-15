<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnershipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'first_name' => $this->first_name ?? null,
            'last_name' => $this->last_name ?? null,
            'date_of_birth' => $this->date_of_birth ?? null,
            'ssn' => null,
            'citizenship_status' => null,
            'street_address' => $this->home_address ?? null,
            'city' => $this->city ?? null,
            'state' => $this->state ?? null,
            'zip_code' => $this->postal_code ?? null,
            'ownership' => null,
            'has_co_borrower' => false,
        ];
    }
}
