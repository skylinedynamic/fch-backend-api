<?php

namespace App\Http\Resources\Borrower\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User\User */
class PersonalProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'home_address' => $this->address_line_1,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->postal_code,
            'country' => $this->country,
            'home_phone' => $this->phone_number_2,
            'mobile_phone' => $this->phone_number,
            'last_4_ssn' => $this->ssn ? substr($this->ssn, -4) : null,
            'date_of_birth' => $this->date_of_birth,
        ];
    }
}