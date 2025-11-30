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
            'phone_number' => $this->entity->phone_number, 
            'country' => $this->entity->country,
            'country_code' => $this->entity->country_code,
            'state' => $this->entity->state,
            'city' => $this->entity->city,
            'zip_code' => $this->entity->postal_code,
            'home_address' => $this->entity->home_address,
            'home_phone' => $this->entity->home_phone,
            'last_4_ssn' => $this->entity->last_4_ssn,
            'date_of_birth' => $this->entity->date_of_birth,
        ];
    }
}