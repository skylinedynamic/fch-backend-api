<?php

namespace App\Rules;

use App\Models\Funding\FundingSequencing;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A funding sequence offered for the given funding type (the application's
 * Preferred Funding Type) - one the Funding Sequence dropdown lists.
 */
class OfferedFundingSequence implements ValidationRule
{
    public function __construct(private ?string $fundingType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $offered = is_numeric($value)
            && $this->fundingType !== null
            && FundingSequencing::offeredFor($this->fundingType)->whereKey((int) $value)->exists();

        if (! $offered) {
            $fail(__('borrower_validation.funding_sequence_invalid'));
        }
    }
}
