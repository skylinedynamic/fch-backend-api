<?php

namespace App\Http\Resources\FCH\Partner;

use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Partner */
class PartnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assigned_user_id' => $this->assignedUser->id ?? null,
            'assigned_user_name' => $this->assignedUser->name ?? null,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'contact_person' => $this->contact_person,
            'contact_person_email' => $this->contact_person_email,
            'contact_person_phone_number' => $this->contact_person_phone_number,
            'contact_person_relation' => $this->contact_person_relation,
            'partner_type' => $this->partner_type,
            'status' => $this->status,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'joining_date' => $this->joining_date,
            'company_logo' => $this->company_logo,
            'partner_image' => $this->getEntityImage(),
            'active_custom_url' => (bool) $this->active_custom_url,
            'partner_domain' => $this->partner_domain,
            'borrower_domain' => $this->borrower_domain,
            'tax_id' => $this->tax_id ?? null, // Assuming model accessor or field exists
            'website' => $this->website ?? null, // Assuming model accessor or field exists
            'is_host' => $this->is_host,
            'user_seats' => $this->relationLoaded('subscription') ? ($this->subscription?->user_seats ?? 3) : 3,
            'per_seat_amount' => $this->relationLoaded('subscription') ? ($this->subscription?->per_seat_amount ?? 49.00) : 49.00,
            'is_free_trail' => (bool) ($this->is_free_trail ?? false),
            'free_trail_end_date' => toDateOnly($this->free_trail_end_date),
            'created_at' => $this->created_at
                ? Carbon::parse($this->created_at)->format('M j, Y')
                : null,
        ];
    }
}
