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
            'first_name' => $this->entity->first_name,
            'last_name' => $this->entity->last_name,
            'email' => $this->email,
            'home_address' => $this->entity->address,
            'city' => $this->entity->city,
            'state' => $this->entity->state,
            'zip_code' => $this->entity->postal_code,
            'country' => $this->entity->country,
            'home_phone' => $this->entity->phone_number_2,
            'phone_number' => $this->entity->phone_number,
            'last_4_ssn' => $this->entity->ssn ? substr($this->entity->ssn, -4) : null,
            'date_of_birth' => $this->entity->date_of_birth,
        ];
    }
}