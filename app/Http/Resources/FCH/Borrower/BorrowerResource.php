<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerResource extends JsonResource
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
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'phone_number'  => $this->phone_number,
            'assign_id'     => $this->assign_id,
            'assign_name'   => $this->assignedUser?->name,
            'partner_name'  => $this->partner?->full_name,
            'partner_id'    => $this->partner?->id,
            'current_tier'  => $this->current_tier,
        ];
    }
}
