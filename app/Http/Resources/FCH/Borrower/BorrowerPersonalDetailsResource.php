<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerPersonalDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'signup_id'     => $this->signup_id,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'phone_number'  => $this->phone_number,
            'country'       => $this->country,
            'country_code'  => $this->country_code,
            'state'         => $this->state,
            'city'          => $this->city,
            'postal_code'   => $this->postal_code,
            'home_address'  => $this->home_address,
            'home_phone'    => $this->home_phone,
            'date_of_birth' => $this->date_of_birth,
            'last_4_ssn'    => $this->last_4_ssn,
            'status'        => $this->status,
            'assign_id'     => $this->assign_id,
            'assign_name'   => $this->assignedUser?->name,
            'partner_name'  => $this->partner?->full_name,
            'partner_id'    => $this->partner?->id,
        ];
    }
}
