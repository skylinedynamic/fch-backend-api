<?php

namespace App\Http\Resources\Borrower\FundApplication;

use App\Models\FundingRequest\FundingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fundingRequestId = $this->funding_request_id 
            ?? $request->input('funding_request_id') 
            ?? $request->input('fundingRequestId') 
            ?? null;

        $fundingReq = null;
        if ($this->resource instanceof \App\Models\Borrower\FundingApplication && $this->relationLoaded('fundingRequest') && $this->fundingRequest) {
            $fundingReq = $this->fundingRequest;
        } elseif ($fundingRequestId) {
            $fundingReq = FundingRequest::with('fundingSequence')->find($fundingRequestId);
        } elseif (auth()->check() && auth()->user()->entity_id) {
            $fundingReq = FundingRequest::with('fundingSequence')
                ->where('borrower_id', auth()->user()->entity_id)
                ->where('status', FundingRequest::STATUS_APPROVED)
                ->latest()
                ->first();
            if ($fundingReq && !$fundingRequestId) {
                $fundingRequestId = $fundingReq->id;
            }
        }

        $fundingSequenceId = $this->funding_sequence_id 
            ?? $request->input('funding_sequence_id') 
            ?? $request->input('fundingSequenceId') 
            ?? null;

        $requestedAmount = $this->requested_amount;
        $purposeOfFunds = $this->purpose_of_funds;
        $approvedFunding = $this->approved_funding;
        $preferredFundingType = $this->preferred_funding_type;
        $timeframeNeeded = $this->timeframe_needed;

        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'funding_request_id' => $fundingReq ? $fundingReq->id : null,
            'funding_sequence_id' => $fundingReq ? $fundingReq->funding_sequence_id : null,
            'requested_amount' => $requestedAmount,
            'purpose_of_funds' => $purposeOfFunds,
            'approved_funding' => $approvedFunding,
            'purpose_note' => $this->purpose_note ?? null,
            'preferred_funding_type' => $preferredFundingType,
            'timeframe_needed' => $timeframeNeeded,
            'collateral_available' => $this->collateral_available ?? null,
        ];
    }
}

