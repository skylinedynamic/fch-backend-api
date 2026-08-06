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
        $borrower = $this->borrower ?? ($this instanceof \App\Models\Borrower\Borrower ? $this : null);

        if (isset($this->borrower_id)) {
            return [
                'id' => $this->id,
                'first_name' => $this->first_name ?? $borrower?->first_name ?? null,
                'last_name' => $this->last_name ?? $borrower?->last_name ?? null,
                'date_of_birth' => $this->date_of_birth ?? $borrower?->date_of_birth ?? null,
                'ssn' => $this->ssn ?? $borrower?->last_4_ssn ?? null,
                'phone_number' => $this->phone_number ?? $borrower?->phone_number ?? $borrower?->home_phone ?? null,
                'country' => $this->country ?? $borrower?->country ?? null,
                'citizenship_status' => $this->citizenship_status ?? 'US Citizen',
                'street_address' => $this->street_address ?? $borrower?->home_address ?? null,
                'city' => $this->city ?? $borrower?->city ?? null,
                'state' => $this->state ?? $borrower?->state ?? null,
                'zip_code' => $this->zip_code ?? $borrower?->postal_code ?? null,
                'ownership' => $this->ownership ?? '100.00',
                'has_co_borrower' => $this->has_co_borrower ?? false,
                'co_borrowers' => CoBorrowerResource::collection($this->whenLoaded('coborrowers') ?? collect()),
            ];
        } else {
            return [
                'id' => null,
                'first_name' => $this->first_name ?? null,
                'last_name' => $this->last_name ?? null,
                'date_of_birth' => $this->date_of_birth ?? null,
                'ssn' => $this->last_4_ssn ?? null,
                'phone_number' => $this->phone_number ?? $this->home_phone ?? null,
                'citizenship_status' => 'US Citizen',
                'country' => $this->country ?? null,
                'street_address' => $this->home_address ?? null,
                'city' => $this->city ?? null,
                'state' => $this->state ?? null,
                'zip_code' => $this->postal_code ?? null,
                'ownership' => '100.00',
                'has_co_borrower' => false,
                'co_borrowers' => [],
            ];
        }
    }
}
