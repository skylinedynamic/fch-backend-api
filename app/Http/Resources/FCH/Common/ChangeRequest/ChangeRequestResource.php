<?php

namespace App\Http\Resources\FCH\Common\ChangeRequest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeRequestResource extends JsonResource
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
            'partner_id' => $this->partner_id,
            'borrower_id' => $this->borrower_id,
            'field_name' => $this->field_name,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'status' => $this->status,
            'note' => $this->note,
            'updated_by' => $this->updated_by,
            'updater_name' => $this->updater?->full_name ?: $this->updater?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'borrower_business_name' => $this->borrower?->business_name,
            'borrower_first_name' => $this->borrower?->first_name,
            'borrower_last_name' => $this->borrower?->last_name,
        ];
    }
}
