<?php

namespace App\Http\Resources\FCH\Partner\FundApplication;

use App\Models\Borrower\FundingApplication;
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
        if ($this->resource instanceof FundingApplication && $this->relationLoaded('fundingRequest') && $this->fundingRequest) {
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
            ?? ($fundingReq ? $fundingReq->funding_sequence_id : null) 
            ?? $request->input('funding_sequence_id') 
            ?? $request->input('fundingSequenceId') 
            ?? null;
        
        $requestedAmount = $this->requested_amount 
            ?? ($fundingReq ? $fundingReq->requested_amount : null);

        $purposeOfFunds = $this->purpose_of_funds 
            ?? ($fundingReq ? $fundingReq->use_of_funds : null);

        $approvedFunding = $this->approved_funding 
            ?? ($fundingReq && $fundingReq->fundingSequence ? $fundingReq->fundingSequence->name : null);

        $preferredFundingType = $this->preferred_funding_type 
            ?? ($fundingReq ? $fundingReq->funding_type : null);

        $timeframeNeeded = $this->timeframe_needed 
            ?? ($fundingReq ? $fundingReq->estimated_speed : null);

        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'funding_request_id' => $fundingRequestId ? (int)$fundingRequestId : null,
            'funding_sequence_id' => $fundingSequenceId ? (int)$fundingSequenceId : null,
            'requested_amount' => $requestedAmount,
            'purpose_of_funds' => $purposeOfFunds,
            'approved_funding' => $approvedFunding,
            'purpose_note' => $this->purpose_note ?? null,
            'preferred_funding_type' => $preferredFundingType,
            'timeframe_needed' => $timeframeNeeded,
            'collateral_available' => $this->collateral_available ?? null,
            'is_business_info_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_business_info_saved : false,
            'is_ownership_info_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_ownership_info_saved : false,
            'is_financial_info_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_financial_info_saved : false,
            'is_funding_request_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_funding_request_saved : false,
            'is_disclosures_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_disclosures_saved : false,
            'is_federal_compliance_saved' => $this->resource instanceof FundingApplication ? (bool)$this->is_federal_compliance_saved : false,
            'signature_data' => $this->resource instanceof FundingApplication ? $this->signature_data : null,
            'status' => $this->resource instanceof FundingApplication ? $this->status : null,
        ];
    }
}


