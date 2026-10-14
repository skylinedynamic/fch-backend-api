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
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');
        
        return [
            'id' => $this->id,
            'first_name' => $this->entity->first_name,
            'middle_name' => $this->entity->middle_name,
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
            // The borrower's own SSN, as nine digits (or just the last four on
            // rows from before the full SSN was collected). The screen masks it
            // and shows it on the eye button; the response is never cached.
            'ssn' => $this->entity->ssn,
            // A calendar date, never timezone-converted - see toDateOnly().
            'date_of_birth' => toDateOnly($this->entity->date_of_birth),
        ];
    }
}