<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Get status from the eager loaded relationship, default to pending
        $borrower_task = $this->borrowerTierTasks->where('borrower_id', auth()->user()->entity_id)->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'status' => $borrower_task ? $borrower_task->status : BorrowerTaskVerificationRequest::PENDING,
            'rejection_reason' => $borrower_task ? $borrower_task->rejection_reason : null,
            'completed_at' => ($borrower_task && $borrower_task->verified_at) ? date('d M Y', strtotime($borrower_task->verified_at)) : null,
            'url' => $this->url,
            'button_title' => $this->button_title,
            'estimated_completion_days' => $this->estimated_completion_days,
            'order' => $this->order,
        ];
    }
}
