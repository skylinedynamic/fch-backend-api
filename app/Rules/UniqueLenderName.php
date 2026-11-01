<?php

namespace App\Rules;

use App\Models\Lender\Lender;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A lender name no other lender has - compared without case or surrounding
 * spaces, so "Kabbage" and " kabbage " are the same name. Add Lender took a
 * name already in use (R2 BUG-008); the name is what borrowers, partners and
 * the funding sequences show, so two alike could not be told apart.
 *
 * A deleted lender's name is free again. Lenders already sharing a name are
 * left as they are; each just cannot be saved under the other's name.
 */
class UniqueLenderName implements ValidationRule
{
    /** @param int|string|null $ignoreId the lender being edited */
    public function __construct(private int|string|null $ignoreId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $taken = Lender::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId !== null, fn ($q) => $q->whereKeyNot($this->ignoreId))
            ->exists();

        if ($taken) {
            $fail(__('lender.name_taken'));
        }
    }
}
