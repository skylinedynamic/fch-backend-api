<?php

namespace App\Services\FCH\Partner;

use App\Models\Partner\PartnerCommissionTier;

/**
 * How a partner commission tier is written for people: its value as "$1,500"
 * (a flat amount per deal) or "27.5%" (a share of the success fee), and its
 * range ends. Shared by the activity log summary and the agreement's
 * {{signer.commission_table}}.
 */
class CommissionTierFormatter
{
    /** @param array{commission_type?:?string, percentage?:mixed, amount?:mixed} $tier */
    public static function value(array $tier): string
    {
        return ($tier['commission_type'] ?? null) === PartnerCommissionTier::TYPE_FLAT
            ? '$' . preg_replace('/\.00$/', '', number_format((float) ($tier['amount'] ?? 0), 2))
            : rtrim(rtrim(number_format((float) ($tier['percentage'] ?? 0), 2, '.', ''), '0'), '.') . '%';
    }

    /** The tier's upper end, or "and up" for the last, open-ended tier. */
    public static function maxLabel(?int $maxCount): string
    {
        return $maxCount === null ? __('agreement.commission.and_up') : (string) $maxCount;
    }
}
