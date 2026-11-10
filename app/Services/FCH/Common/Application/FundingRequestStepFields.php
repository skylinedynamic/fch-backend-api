<?php

namespace App\Services\FCH\Common\Application;

use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;

/**
 * The Funding Request step fields a save of an existing application may not
 * set freely - one set of rules for the borrower and the admin / partner
 * wizards (and their FormRequests):
 *
 *  - Preferred Funding Type is read-only once the application has one.
 *  - An application that came from a funding request keeps the funding
 *    sequence (and approved_funding name) it was approved for.
 *  - An application that did not (converted from the embed form) has its
 *    funding sequence chosen on this step, from those offered for its
 *    Preferred Funding Type; approved_funding is that sequence's name.
 */
class FundingRequestStepFields
{
    /**
     * The Preferred Funding Type the application is held to: its own, or,
     * when it has none yet, its funding request's. Null when neither has one.
     */
    public function lockedPreferredFundingType(FundingApplication $application): ?string
    {
        $type = $application->preferred_funding_type;

        if (blank($type) && $application->funding_request_id) {
            $type = FundingRequest::whereKey($application->funding_request_id)->value('funding_type');
        }

        return blank($type) ? null : $type;
    }

    /**
     * Whether this save chooses the application's funding sequence: only an
     * application converted from the embed form (it has no funding request),
     * saved without naming one.
     */
    public function choosesFundingSequence(?FundingApplication $application, mixed $namedFundingRequestId): bool
    {
        return $application !== null
            && $application->exists
            && blank($application->funding_request_id)
            && blank($namedFundingRequestId)
            && $application->isFromEmbedForm();
    }

    /**
     * The save's data with the read-only fields put back to what the
     * application holds: the Preferred Funding Type, and the funding sequence
     * (and its approved_funding name) - except on the Funding Request step of
     * an embed form application, where the chosen sequence and its name are
     * taken. Every step goes through this: a later step's save must not put
     * back an older sequence id the wizard still holds.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(?FundingApplication $existing, array $data): array
    {
        if (! $existing || ! $existing->exists) {
            return $data;
        }

        $isFundingRequestStep = array_key_exists('preferred_funding_type', $data);

        if ($isFundingRequestStep) {
            $locked = $this->lockedPreferredFundingType($existing);
            if ($locked !== null) {
                $data['preferred_funding_type'] = $locked;
            }

            if ($this->choosesFundingSequence($existing, $data['funding_request_id'] ?? null)) {
                return $this->withChosenSequence($existing, $data);
            }
        }

        // Being attached to another funding request by this save: the
        // services' own funding request rules decide.
        $namedRequest = $data['funding_request_id'] ?? null;
        if (filled($namedRequest) && (string) $namedRequest !== (string) $existing->funding_request_id) {
            return $data;
        }

        if (filled($existing->funding_sequence_id) && array_key_exists('funding_sequence_id', $data)) {
            $data['funding_sequence_id'] = $existing->funding_sequence_id;
        }
        if ($isFundingRequestStep && filled($existing->approved_funding)) {
            $data['approved_funding'] = $existing->approved_funding;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withChosenSequence(FundingApplication $existing, array $data): array
    {
        $sequence = blank($data['funding_sequence_id'] ?? null)
            ? null
            : FundingSequencing::offeredFor($data['preferred_funding_type'])->find($data['funding_sequence_id'], ['id', 'name']);

        if ($sequence) {
            $data['funding_sequence_id'] = $sequence->id;
            $data['approved_funding'] = $sequence->name;
        } else {
            // Never a sequence that is not offered for the type; validation
            // has already refused one, so this only guards other callers.
            $data['funding_sequence_id'] = $existing->funding_sequence_id;
            $data['approved_funding'] = $existing->approved_funding;
        }

        return $data;
    }
}
