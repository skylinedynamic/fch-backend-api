<?php

namespace App\Rules;

use App\Models\Lender\Lender;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A lender's legal name is its own (R2 BUG-008): no other lender may have it,
 * whatever its case or spacing. A deleted lender's legal name is free again.
 */
class UniqueLenderLegalName implements ValidationRule
{
    /** @param  int|string|null  $ignoreId  the lender being edited */
    public function __construct(private int|string|null $ignoreId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Lender::legalNameTaken($value, $this->ignoreId)) {
            $fail(__('lender.legal_name_unique'));
        }
    }
}
