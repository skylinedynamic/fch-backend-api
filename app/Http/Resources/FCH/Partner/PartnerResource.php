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
                'id'                           => $this->id,
                'assigned_user_id'             => $this->assignedUser->id ?? null,
                'assigned_user_name'           => $this->assignedUser->name ?? null,
                'company_name'                 => $this->company_name,
                'company_email'                => $this->company_email,
                'company_phone_number'         => $this->company_phone_number,
                'owner_name'                   => $this->owner_name,
                'owner_email'                  => $this->owner_email,
                'owner_phone_number'           => $this->owner_phone_number,
                'contact_person'               => $this->contact_person,
                'contact_person_email'         => $this->contact_person_email,
                'contact_person_phone_number'  => $this->contact_person_phone_number,
                'partner_type'                 => $this->partner_type,
                'is_host'                      => $this->is_host,
                'created_at'                   => $this->created_at 
                    ? Carbon::parse($this->created_at)->format('M j, Y') 
                    : null,
            ];
        }
}