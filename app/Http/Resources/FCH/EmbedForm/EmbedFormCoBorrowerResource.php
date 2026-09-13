<?php

namespace App\Http\Resources\FCH\EmbedForm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Crypt;

/** @mixin \App\Models\EmbedForm\EmbedFormCoBorrower */
class EmbedFormCoBorrowerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'title_role' => $this->title_role,
            'ownership_percentage' => $this->ownership_percentage,
            'email' => $this->email,
            'mobile_phone' => $this->mobile_phone,
            'home_phone' => $this->home_phone,
            'date_of_birth' => toDateOnly($this->date_of_birth),
            // Only the last four digits leave the server, matching how the
            // primary owner's SSN is exposed.
            'last_4_ssn' => $this->lastFourSsn(),
            'street_address' => $this->street_address,
            'country' => $this->country,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'drivers_license_number' => $this->drivers_license_number,
            'drivers_license_state' => $this->drivers_license_state,
        ];
    }

    protected function lastFourSsn(): ?string
    {
        if (!$this->ssn_encrypted) {
            return null;
        }

        try {
            return substr(Crypt::decryptString($this->ssn_encrypted), -4);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
