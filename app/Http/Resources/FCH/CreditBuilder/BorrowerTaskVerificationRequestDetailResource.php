<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerTaskVerificationRequestDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'task_id' => $this->task_id,
            'task_title' => $this->task?->title,
            'tier_id' => $this->tier_id,
            'tier_name' => $this->tier?->name,
            'status' => $this->status,
            'request_date' => $this->created_at ? (Carbon::parse($this->created_at)->format('F d, Y h:i A')) : null,
        ];
    }
}
