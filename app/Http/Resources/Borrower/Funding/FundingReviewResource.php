<?php

namespace App\Http\Resources\Borrower\Funding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingReviewResource extends JsonResource
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
            'borrower_id' => $this->borrower_id,
            'funding_type' => $this->funding_type,
            'requested_amount' => $this->requested_amount,
            'use_of_funds' => $this->use_of_funds,
            'status' => $this->status,
            'reject_reason' => $this->reject_reason,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'funding_sequence' => [
                'id' => $this->fundingSequence->id ?? null,
                'name' => $this->fundingSequence->name ?? null,
            ],
            'borrower' => [
                'id' => $this->borrower->id ?? null,
                'first_name' => $this->borrower->first_name ?? null,
                'last_name' => $this->borrower->last_name ?? null,
                'company_name' => $this->borrower->company_name ?? null,
            ],
            'approved_by_name' => $this->approved_by ? ($this->approver->name ?? null) : null,
        ];
    }
}
