<?php

namespace App\Http\Resources\Borrower\FundApplication;

use App\Models\Borrower\FundingApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttestationAndAuthorizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'attestation_authorization' => $this->attestation_authorization ? true : false,
            'signature_data' => $this->signature_data,
            // An edit cleared the signature above; the borrower owes a new one.
            // The one it replaced is shown beside the field, never prefilled into it.
            'requires_resign' => (bool) ($this->requires_resign ?? false),
            'previous_signature' => $this->resource instanceof FundingApplication
                ? $this->resource->previousSignature()
                : null,
            'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
            'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
        ];
    }
}
