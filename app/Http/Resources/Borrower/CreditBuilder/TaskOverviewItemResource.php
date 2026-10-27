<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskOverviewItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'id' => $this->task->id,
            'title' => $this->task->title,
            'subtitle' => $this->task->subtitle,
            'description' => $this->task->description,
            'tier' => $this->tier->name,
            'status' => $this->status,
            // The reviewer's note is the reason on a rejected request (BUG-083).
            'rejection_reason' => $this->status === BorrowerTaskVerificationRequest::REJECTED ? $this->note : null,
            'completed_at' => ($this->verified_at) ? date('d M Y', strtotime($this->verified_at)) : null,
        ];
    }
}
