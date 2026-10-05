<?php

namespace App\Http\Resources\FCH\Partner\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
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
        $fundingReq = null;
        $fundingApp = null;

        if ($this->resource instanceof FundingApplication) {
            $fundingApp = $this->resource;
            $fundingReq = $this->relationLoaded('fundingRequest') ? $this->fundingRequest : null;
        } elseif ($this->resource instanceof FundingRequest) {
            $fundingReq = $this->resource;
            $fundingApp = $this->relationLoaded('fundingApplication') ? $this->fundingApplication : null;
        }

        $fundingRequestId = ($fundingApp ? $fundingApp->funding_request_id : null)
            ?? ($fundingReq ? $fundingReq->id : null)
            ?? $request->input('funding_request_id') 
            ?? $request->input('fundingRequestId') 
            ?? null;

        if (!$fundingReq && $fundingRequestId) {
            $fundingReq = FundingRequest::with('fundingSequence')->find($fundingRequestId);
        }

        if (!$fundingReq && auth()->check() && auth()->user()->entity_id) {
            $fundingReq = FundingRequest::with('fundingSequence')
                ->where('borrower_id', auth()->user()->entity_id)
                ->where('status', FundingRequest::STATUS_APPROVED)
                ->latest()
                ->first();
            if ($fundingReq && !$fundingRequestId) {
                $fundingRequestId = $fundingReq->id;
            }
        }

        $fundingSequenceId = ($fundingApp ? $fundingApp->funding_sequence_id : null)
            ?? ($fundingReq ? $fundingReq->funding_sequence_id : null)
            ?? $request->input('funding_sequence_id') 
            ?? $request->input('fundingSequenceId') 
            ?? null;

        $requestedAmount = ($fundingApp ? $fundingApp->requested_amount : null)
            ?? ($fundingReq ? $fundingReq->requested_amount : null);

        $purposeOfFunds = ($fundingApp ? $fundingApp->purpose_of_funds : null)
            ?? ($fundingReq ? $fundingReq->use_of_funds : null);

        $approvedFunding = ($fundingApp ? $fundingApp->approved_funding : null)
            ?? ($fundingReq && $fundingReq->fundingSequence ? $fundingReq->fundingSequence->name : null);

        if (!$approvedFunding && $fundingSequenceId) {
            $seq = FundingSequencing::find($fundingSequenceId);
            if ($seq) {
                $approvedFunding = $seq->name;
            }
        }

        if (!$approvedFunding && $fundingReq) {
            $approvedFunding = $fundingReq->funding_type ?: $fundingReq->use_of_funds;
        }

        if (!$approvedFunding && $fundingRequestId) {
            $seq = FundingSequencing::find($fundingRequestId);
            if ($seq) {
                $approvedFunding = $seq->name;
            }
        }

        $preferredFundingType = ($fundingApp ? $fundingApp->preferred_funding_type : null)
            ?? ($fundingReq ? $fundingReq->funding_type : null);

        $timeframeNeeded = ($fundingApp ? $fundingApp->timeframe_needed : null)
            ?? ($fundingReq ? $fundingReq->estimated_speed : null);

        return [
            'id' => $fundingApp ? $fundingApp->id : null,
            'funding_request_id' => $fundingRequestId ? (int)$fundingRequestId : null,
            'funding_sequence_id' => $fundingSequenceId ? (int)$fundingSequenceId : null,
            'requested_amount' => $requestedAmount,
            'purpose_of_funds' => $purposeOfFunds,
            'approved_funding' => $approvedFunding,
            'purpose_note' => $fundingApp ? $fundingApp->purpose_note : null,
            'preferred_funding_type' => $preferredFundingType,
            'timeframe_needed' => $timeframeNeeded,
            'collateral_available' => $fundingApp ? $fundingApp->collateral_available : null,
            'is_business_info_saved' => $fundingApp ? (bool)$fundingApp->is_business_info_saved : false,
            'is_ownership_info_saved' => $fundingApp ? (bool)$fundingApp->is_ownership_info_saved : false,
            'is_financial_info_saved' => $fundingApp ? (bool)$fundingApp->is_financial_info_saved : false,
            'is_funding_request_saved' => $fundingApp ? (bool)$fundingApp->is_funding_request_saved : false,
            'is_disclosures_saved' => $fundingApp ? (bool)$fundingApp->is_disclosures_saved : false,
            'is_federal_compliance_saved' => $fundingApp ? (bool)$fundingApp->is_federal_compliance_saved : false,
            'signature_data' => $fundingApp ? $fundingApp->signature_data : null,
            'status' => $fundingApp ? $fundingApp->status : null,

            // What the partner is allowed to do with this application, and what
            // an edit costs. `edit_requires_resign` is the one the partner portal
            // acts on: a change to an application the borrower has already signed
            // withdraws *their* signature, so the partner is warned before it
            // happens rather than after.
            ...($fundingApp ? $fundingApp->editingState() : self::noApplicationEditingState()),
        ];
    }

    /**
     * There is no application yet, so there is nothing to lock and no signature
     * to invalidate - the partner is free to prepare one.
     *
     * @return array<string, mixed>
     */
    private static function noApplicationEditingState(): array
    {
        return [
            'is_locked' => false,
            'is_editable' => true,
            'requires_resign' => false,
            'edit_requires_resign' => false,
            'can_start_new_funding_request' => false,
            'locked_reason' => null,
        ];
    }
}


