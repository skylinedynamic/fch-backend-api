<?php

namespace App\Http\Resources\Borrower\FundApplication;

use App\Http\Resources\Borrower\FundApplication\CoBorrowerResource;
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
        $borrower = $this->borrower ?? ($this->resource instanceof \App\Models\Borrower\Borrower ? $this->resource : null);
        $owner = $this->coborrowers ? $this->coborrowers->firstWhere('title_role', 'Owner') : null;

        if ($owner) {
            $firstName = $owner->first_name;
            $lastName = $owner->last_name;
            $email = $owner->email;
            $mobilePhone = $owner->mobile_phone;
            $homePhone = $owner->home_phone;
            $dob = toDateOnly($owner->date_of_birth);
            $ssn = $owner->ssn;
            $streetAddress = $owner->street_address;
            $city = $owner->city;
            $state = $owner->state;
            $zip = $owner->zip_code;
            $country = $owner->country;
            $citizenship = $owner->citizenship;
            $ownershipPct = $owner->ownership_percentage;
        } else {
            $firstName = $this->first_name ?? $borrower?->first_name ?? null;
            $lastName = $this->last_name ?? $borrower?->last_name ?? null;
            $email = $borrower?->email ?? null;
            $mobilePhone = $this->phone_number ?? $borrower?->phone_number ?? null;
            $homePhone = null;
            
            $dobRaw = $this->date_of_birth ?? $borrower?->date_of_birth;
            $dob = null;
            if ($dobRaw) {
                if ($dobRaw instanceof \DateTimeInterface) {
                    $dob = toDateOnly($dobRaw);
                } else {
                    $dob = toDateOnly($dobRaw);
                }
            }
                
            $ssn = $this->ssn ?? $borrower?->last_4_ssn ?? null;
            $streetAddress = $this->street_address ?? $borrower?->home_address ?? null;
            $city = $this->city ?? $borrower?->city ?? null;
            $state = $this->state ?? $borrower?->state ?? null;
            $zip = $this->zip_code ?? $borrower?->postal_code ?? null;
            $country = $this->country ?? $borrower?->country ?? null;
            
            $citizenship = $this->citizenship_status ?? null;
            if (!$citizenship && $borrower && method_exists($borrower, 'citizenship')) {
                $citizenship = $borrower->citizenship;
            }
            
            $ownershipPct = $this->ownership ?? null;
        }

        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone_number' => $mobilePhone ?? $homePhone,
            'date_of_birth' => $dob,
            'ssn' => $ssn,
            'street_address' => $streetAddress,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
            'country' => $country,
            'citizenship_status' => $citizenship ?? 'US Citizen',
            'ownership' => $ownershipPct ?? '100.00',
            'has_co_borrower' => $this->has_co_borrower ?? false,
            'co_borrowers' => CoBorrowerResource::collection($this->whenLoaded('coborrowers') ?? collect()),
            'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
            'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
        ];
    }
}