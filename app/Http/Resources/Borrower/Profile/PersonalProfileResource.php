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
            'home_address' => $this->entity->address_line_1,
            'city' => $this->entity->city,
            'state' => $this->entity->state,
            'zip_code' => $this->entity->postal_code,
            'country' => $this->entity->country,
            'country_code' => $this->entity->country_code,
            'home_phone' => $this->entity->home_phone,
            'phone_number' => $this->phone_number,
            'last_4_ssn' => $this->entity->last_4_ssn,
            'date_of_birth' => $this->entity->date_of_birth,
        ];
    }
}