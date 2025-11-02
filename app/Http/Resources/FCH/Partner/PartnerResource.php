<?php

namespace App\Http\Resources\FCH\Partner;

use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class PartnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
     public function toArray(Request $request): array
        {
            return [
                'id' => $this->id,
                'partner_name' => trim($this->full_name ?? '') ?: null,
                'company_name' => $this->company->company_name ?? null,
                'company_logo' => $this->company->company_logo 
                    ? asset('storage/' . $this->company->company_logo) 
                    : null,
                'created_at' => $this->company->created_at 
                    ? Carbon::parse($this->company->created_at)->format('M j, Y') 
                    : null,
                'email' => $this->email,
                'phone_number' => $this->phone_number,
                'website' => $this->company->company_website ?? null,
                'state' => $this->state,
                'city' => $this->city,
                'country' => $this->country,
                'zip_code' => $this->postal_code,
                'tax_id' => $this->tax_identification_number ?? null,
                'status' => $this->company->status ?? null,
                'level' => 'Gold Level',  // #TODO: dynamic later
                'deals' => '12',   // #TODO: dynamic later
                'agent' => 'New User',   // #TODO
                // 'agent' => $this->roles->contains('role_name', 'agent') ? 'Agent' : null,
            ];
        }
}