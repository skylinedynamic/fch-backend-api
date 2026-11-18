<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Funding-application count tiers (0-10, 11-20, 21 and up) must cover every
 * deal exactly once: the first starts at 0, each next one starts right after
 * the previous one ends, and only the last may be open-ended. Each problem is
 * reported under the field it's on ("{prefix}.{j}.min_count"), so the screen
 * can show it under that input.
 *
 * Used by the partner commission request; the per-field rules (required,
 * integer, min:0) stay in the request.
 */
trait ValidatesCommissionTiers
{
    protected function validateTierRanges(Validator $validator, mixed $tiers, string $prefix): void
    {
        if (!is_array($tiers) || $tiers === []) {
            return;
        }

        $tiers = array_values($tiers);
        $last = count($tiers) - 1;
        $previousMax = null;

        foreach ($tiers as $j => $tier) {
            $min = $this->tierCount($tier['min_count'] ?? null);
            $maxRaw = $tier['max_count'] ?? null;
            $max = $this->tierCount($maxRaw);

            // A malformed value is already reported by the per-field rules.
            if ($min === null || ($maxRaw !== null && $maxRaw !== '' && $max === null)) {
                return;
            }

            if ($j === 0 && $min !== 0) {
                $validator->errors()->add("{$prefix}.{$j}.min_count", __('commission.tier.first_starts_at_zero'));
            } elseif ($j > 0 && $previousMax !== null && $min !== $previousMax + 1) {
                $validator->errors()->add("{$prefix}.{$j}.min_count", __('commission.tier.starts_after_previous', ['count' => $previousMax + 1]));
            }

            if ($max === null && $j !== $last) {
                $validator->errors()->add("{$prefix}.{$j}.max_count", __('commission.tier.only_last_open_ended'));

                return;
            }

            if ($max !== null && $max < $min) {
                $validator->errors()->add("{$prefix}.{$j}.max_count", __('commission.tier.ends_before_start'));
            }

            $previousMax = $max;
        }
    }

    private function tierCount(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value) || (int) $value != $value) {
            return null;
        }

        return (int) $value;
    }
}
