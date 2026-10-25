<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One owner's share of the business, which together with everyone else's must
 * not pass 100% (BUG-108).
 *
 * Each share used to be checked on its own, so an owner at 100% and a
 * co-borrower at 50% - 150% of one business - were saved. The 0-100 range of
 * the single share stays `min:0|max:100`'s job; this rule adds the others.
 *
 * Shared by the ownership step and the "+ Add Co-Borrower" check of both the
 * borrower and the partner portal.
 */
class OwnershipWithinTotal implements ValidationRule
{
    /** @param float $others What the other owners already hold, in percent. */
    public function __construct(private float $others)
    {
    }

    /** The sum of the ownership given for these co-borrowers. */
    public static function sumOf(mixed $coBorrowers): float
    {
        return (float) collect(is_array($coBorrowers) ? $coBorrowers : [])
            ->sum(fn ($coBorrower) => is_array($coBorrower) && is_numeric($coBorrower['ownership'] ?? null)
                ? (float) $coBorrower['ownership']
                : 0);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value)) {
            return;
        }

        $total = round((float) $value + $this->others, 2);

        if ($total > 100) {
            $fail(__('borrower_validation.ownership_total_max', ['total' => rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.')]));
        }
    }
}
