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
            'first_name' => $this->employee->first_name,
            'last_name' => $this->employee->last_name,
            'email' => $this->email,
            'home_address' => $this->employee->address,
            'city' => $this->employee->city,
            'state' => $this->employee->state,
            'zip_code' => $this->employee->postal_code,
            'country' => $this->employee->country,
            'home_phone' => $this->employee->phone_number_2,
            'mobile_phone' => $this->employee->phone_number,
            'last_4_ssn' => $this->employee->ssn ? substr($this->employee->ssn, -4) : null,
            'date_of_birth' => $this->employee->date_of_birth,
        ];
    }
}