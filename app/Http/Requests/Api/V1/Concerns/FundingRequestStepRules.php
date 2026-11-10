<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Borrower\FundingApplication;
use App\Rules\KnownFundingType;
use App\Rules\OfferedFundingSequence;
use App\Services\FCH\Common\Application\FundingRequestStepFields;
use Illuminate\Validation\Rule;

/**
 * The Funding Request step rules shared by the borrower and the admin /
 * partner wizards (see FundingRequestStepFields):
 *  - Preferred Funding Type cannot be changed on an existing application;
 *  - an application without a funding request must choose a funding sequence
 *    offered for that type (approved_funding is then set from it);
 *  - an application from a funding request keeps its own.
 */
trait FundingRequestStepRules
{
    /** The existing application this save is for, when the caller may reach it. */
    abstract protected function targetApplication(): ?FundingApplication;

    /** @return array<string, array<int, mixed>> */
    protected function fundingSequenceRules(): array
    {
        $application = once(fn () => $this->targetApplication());
        $fields = app(FundingRequestStepFields::class);

        $locked = $application ? $fields->lockedPreferredFundingType($application) : null;
        $chooses = $fields->choosesFundingSequence(
            $application,
            $this->input('funding_request_id') ?? $this->input('fundingRequestId')
        );

        return [
            'funding_sequence_id' => $chooses
                ? ['required', new OfferedFundingSequence($locked ?? $this->input('preferred_funding_type'))]
                : ['nullable'],
            'approved_funding' => $chooses
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            'preferred_funding_type' => $locked !== null
                ? ['nullable', 'string', Rule::in([$locked])]
                // An Active lender product category, as offered (Lenders > Product Categories).
                : ['required', 'string', 'max:255', new KnownFundingType('borrower_validation.preferred_funding_type_invalid')],
        ];
    }

    /** @return array<string, string> */
    protected function fundingSequenceMessages(): array
    {
        return [
            'funding_sequence_id.required' => __('borrower_validation.funding_sequence_required'),
            'preferred_funding_type.in' => __('borrower_validation.preferred_funding_type_locked'),
        ];
    }

    private function targetApplicationId(): ?int
    {
        $id = $this->input('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
