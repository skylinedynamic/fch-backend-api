<?php

namespace App\Http\Resources\Borrower\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin \App\Models\Borrower\CreditProfileRequest */
class CreditProfileRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return [];
        }

        $timezone = $request->header('X-Timezone') ?? config('app.timezone');

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
            'date_of_birth' => $this->date_of_birth ? Carbon::parse($this->date_of_birth)->timezone($timezone)->format('Y-m-d') : null,
            'phone' => $this->phone,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
