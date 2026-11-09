<?php

namespace App\Rules;

use App\Models\Lender\LenderProductCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Preferred / Additional Funding Type: an Active lender product category
 * (LenderProductCategory::fundingTypeNames()) - exactly the list the wizards
 * and the embed form offer, so it is a category lenders are matched on.
 */
class KnownFundingType implements ValidationRule
{
    /** @param  string  $message  translation key of the message shown on failure */
    public function __construct(private string $message = 'borrower_validation.additional_funding_type_invalid')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, LenderProductCategory::fundingTypeNames(), true)) {
            $fail(__($this->message));
        }
    }
}
