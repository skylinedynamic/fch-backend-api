<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A business credit score: a whole number of 0 or more, with no upper bound.
 *
 * Used for the borrower's paydex_score and the lender threshold compared
 * against it (min_business_credit_score). No upper bound on purpose: the
 * business score is not tied to one bureau's scale. 0 is a real score.
 *
 * The admin client paths had it as plain `numeric`, so -5 and 12.5 were saved.
 *
 * Whether the field may be left out is still `required` / `nullable`'s job - an
 * empty value passes here, so an optional score stays optional.
 */
class BusinessCreditScore implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $score = FicoScore::wholeNumber($value);

        if ($score === null || $score < 0) {
            $fail(__('common.business_credit_score_invalid'));
        }
    }
}
