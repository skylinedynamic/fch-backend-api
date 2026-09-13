<?php

namespace App\Http\Resources\FCH\Partner\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoBorrowerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'role' => $this->title_role,
            'ownership' => $this->ownership_percentage, // Mapped back to frontend key
            'email' => $this->email,
            'mobile_phone' => $this->mobile_phone,
            'home_phone' => $this->home_phone,
            'date_of_birth' => toDateOnly($this->date_of_birth),
            'ssn' => $this->ssn,
            'street_address' => $this->street_address,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'drivers_license_number' => $this->drivers_license_number,
            'drivers_license_state' => $this->drivers_license_state,
        ];
    }
}

