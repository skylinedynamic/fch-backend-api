<?php

namespace App\Http\Resources\FCH\Common\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardLeadResource extends JsonResource
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
            'lead_id' => $this->lead_id,
            'contact_person' => $this->contact_person,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'lead_type' => $this->lead_type,
            'status' => $this->status,
            'formatted_created_at' => $this->created_at?->format('M d, Y'),
        ];
    }
}
