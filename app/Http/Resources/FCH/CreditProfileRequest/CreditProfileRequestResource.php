<?php

namespace App\Http\Resources\FCH\CreditProfileRequest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Borrower\CreditProfileRequest */
class CreditProfileRequestResource extends JsonResource
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
            'borrower_id' => $this->borrower_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'address' => $this->address,
            'apt_suite_other' => $this->apt_suite_other,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'ssn' => $this->ssn,
            // A calendar date, never timezone-converted - see toDateOnly().
            // Sent ready to display ("May 12, 1990") so the portal prints it
            // as-is: parsing it in the browser is what shifts the day.
            'date_of_birth' => toDateOnly($this->date_of_birth, 'F j, Y'),
            'phone' => $this->phone,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'borrower' => $this->whenLoaded('borrower'),
            // Set by the controller, already narrowed to the host partner.
            'credit_authorizations' => $this->credit_authorizations,
            'credit_last_pulled_at' => $this->credit_last_pulled_at,
        ];
    }
}
