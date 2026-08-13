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
        $borrower = $this->borrower ?? ($this instanceof \App\Models\Borrower\Borrower ? $this : null);
        $owner = $this->coborrowers ? $this->coborrowers->firstWhere('title_role', 'Owner') : null;

        if ($owner) {
            $firstName = $owner->first_name;
            $lastName = $owner->last_name;
            $email = $owner->email;
            $mobilePhone = $owner->mobile_phone;
            $homePhone = $owner->home_phone;
            $dob = $owner->date_of_birth ? date('Y-m-d', strtotime($owner->date_of_birth)) : null;
            $ssn = $owner->ssn;
            $streetAddress = $owner->street_address;
            $city = $owner->city;
            $state = $owner->state;
            $zip = $owner->zip_code;
            $country = $owner->country;
            $citizenship = $owner->citizenship;
            $ownershipPct = $owner->ownership_percentage;
        } else {
            $firstName = $borrower?->first_name ?? null;
            $lastName = $borrower?->last_name ?? null;
            $email = $borrower?->email ?? null;
            $mobilePhone = $borrower?->phone_number ?? null;
            $homePhone = null;
            
            $dob = $borrower?->date_of_birth 
                ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $borrower->date_of_birth) 
                    ? $borrower->date_of_birth 
                    : date('Y-m-d', strtotime($borrower->date_of_birth)))
                : null;
                
            $ssn = $borrower?->last_4_ssn ?? null;
            $streetAddress = $borrower?->home_address ?? null;
            $city = $borrower?->city ?? null;
            $state = $borrower?->state ?? null;
            $zip = $borrower?->postal_code ?? null;
            $country = $borrower?->country ?? null;
            
            $citizenship = null;
            if ($borrower && method_exists($borrower, 'citizenship')) {
                $citizenship = $borrower->citizenship;
            }
            
            $ownershipPct = null;
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
        ];
    }
}
