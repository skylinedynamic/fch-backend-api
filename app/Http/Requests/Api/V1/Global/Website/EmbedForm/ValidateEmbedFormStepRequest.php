<?php

namespace App\Http\Requests\Api\V1\Global\Website\EmbedForm;

/**
 * Validates a single page of the public embedded form when the applicant
 * presses Continue, using the very same rules as the final submission so the
 * two can never disagree. The origin allow-list, input normalisation and
 * messages are all inherited.
 */
class ValidateEmbedFormStepRequest extends SubmitEmbedFormRequest
{
    public const LAST_STEP = 8;

    public function rules(): array
    {
        return match ((int) $this->route('step')) {
            1 => $this->businessInfoRules(),
            2 => array_merge($this->ownershipInfoRules(), $this->coBorrowerRules()),
            3 => $this->financialInfoRules(),
            4 => $this->fundingRequestRules(),
            5 => $this->documentRules(),
            6 => $this->disclosureRules(),
            7 => $this->federalComplianceRules(),
            8 => array_merge($this->attestationRules(), $this->trackingRules()),
            default => [],
        };
    }
}
