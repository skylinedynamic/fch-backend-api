<?php

namespace App\Http\Resources\Borrower\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
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
        // When a signature was given is shown to the borrower in their own
        // timezone (the X-Timezone header), already formatted, so the portal
        // prints it as-is and needs no timezone label beside it.
        $signedAt = static function ($signature) {
            if (is_array($signature) && ! empty($signature['signed_at'])) {
                $signature['signed_at'] = toTimezone($signature['signed_at'], null, 'M d, Y g:i A');
            }

            return $signature;
        };

        return [
            'id' => isset($this->borrower_id) ? $this->id : null,
            'attestation_authorization' => $this->attestation_authorization ? true : false,
            'signature_data' => $signedAt($this->signature_data),
            // An edit cleared the signature above; the borrower owes a new one.
            // The one it replaced is shown beside the field, never prefilled into it.
            'requires_resign' => (bool) ($this->requires_resign ?? false),
            // How the Borrower Agreement's Signature block is set up in the
            // agreement builder, so this signature is styled the same way.
            // What a signature image may be - the same limits the server
            // enforces when one is picked - so the portal's hint states them.
            'signature_rules' => [
                'max_file_kb' => (int) config('funding_application.signature.max_file_kb', 1024),
                'mime_types' => (array) config('funding_application.signature.mime_types', ['image/png', 'image/jpeg']),
            ],
            'signature_block' => app(AgreementService::class)->borrowerSignatureBlock($request->user()?->borrower),
            'previous_signature' => $this->resource instanceof FundingApplication
                ? $signedAt($this->resource->previousSignature())
                : null,
            'funding_sequence_id' => $this->funding_sequence_id ?? $request->input('funding_sequence_id') ?? $request->input('fundingSequenceId') ?? null,
            'funding_request_id' => $this->funding_request_id ?? $request->input('funding_request_id') ?? $request->input('fundingRequestId') ?? null,
        ];
    }
}
