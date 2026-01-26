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
        if(isset($this->borrower_id)){
            return [
                'id' => $this->id,
                'first_name' => $this->first_name ?? $this->borrower->first_name,
                'last_name' => $this->last_name ?? $this->borrower->last_name,
                'date_of_birth' => $this->date_of_birth ?? $this->borrower->date_of_birth,
                'ssn' => $this->ssn ?? null,
                'country' => $this->country ?? $this->borrower->country,
                'citizenship_status' => $this->citizenship_status ?? null,
                'street_address' => $this->street_address ?? $this->borrower->home_address,
                'city' => $this->city ?? $this->borrower->city,
                'state' => $this->state ?? $this->borrower->state,
                'zip_code' => $this->zip_code ?? $this->borrower->postal_code,
                'ownership' => $this->ownership ?? null,
                'has_co_borrower' => $this->has_co_borrower ?? null,
                'co_borrowers' => CoBorrowerResource::collection($this->whenLoaded('coborrowers')),
            ];
        }else{
            return [
                'id' => null,
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'date_of_birth' => $this->date_of_birth,
                'ssn' => null,
                'citizenship_status' => null,
                'country' => $this->country,
                'street_address' => $this->home_address,
                'city' => $this->city,
                'state' => $this->state,
                'zip_code' => $this->postal_code,
                'ownership' => null,
                'has_co_borrower' => null,
                'co_borrowers' => [],
            ];
        }
    }
}
