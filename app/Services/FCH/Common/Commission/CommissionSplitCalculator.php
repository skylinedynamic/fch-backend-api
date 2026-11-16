<?php

namespace App\Services\FCH\Common\Commission;

use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;

/**
 * The admin / partner split of a funding application's success fee.
 *
 * A partner client's deal that falls in one of the partner's commission tiers
 * (Partner profile › Commissions) takes its split from that tier - it can't be
 * typed over:
 * - Percentage tier: the partner gets that % of the success fee;
 * - Flat Amount tier: the partner gets exactly that $, never more than
 *   Partner::COMMISSION_MAX % of the fee (the admin keeps at least 1%).
 * With no tier the typed % is used, as before.
 */
class CommissionSplitCalculator
{
    public function __construct(private CommissionTierResolver $tiers)
    {
    }

    /**
     * How the partner's share is set for this application: its tier for the
     * deal, or null (a host client, a partner with no tier for this deal).
     *
     * @return array{type: string, percentage: ?float, amount: ?float}|null
     */
    public function tierShareFor(FundingApplication $application): ?array
    {
        $partnerId = $application->partner_id ?: $application->borrower?->partner_id;
        $partner = $partnerId ? Partner::query()->find($partnerId) : null;

        if (!$partner || $partner->is_host) {
            return null;
        }

        $tier = CommissionTierResolver::pick($partner->commissionTiers()->get(), $this->tiers->partnerPosition($partner, $application));

        if (!$tier) {
            return null;
        }

        return $tier->isFlat()
            ? ['type' => PartnerCommissionTier::TYPE_FLAT, 'percentage' => null, 'amount' => (float) $tier->amount]
            : ['type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => (float) $tier->percentage, 'amount' => null];
    }

    /**
     * The split of a success fee of $totalAmount.
     *
     * @param string|null $type PartnerCommissionTier::TYPE_FLAT for a flat $; anything else is a %
     * @return array{partner_commission: float, admin_commission: float, partner_commission_amount: float, admin_commission_amount: float}
     */
    public static function split(float $totalAmount, ?string $type, ?float $partnerPercentage, ?float $flatAmount): array
    {
        if ($type === PartnerCommissionTier::TYPE_FLAT) {
            $cap = $totalAmount * Partner::COMMISSION_MAX / 100;
            $partnerAmount = round(min(max((float) $flatAmount, 0), $cap), 2);
            $partnerPercentage = $totalAmount > 0 ? round($partnerAmount / $totalAmount * 100, 2) : 0.0;
        } else {
            $partnerPercentage = max((float) $partnerPercentage, 0);
            $partnerAmount = $totalAmount * $partnerPercentage / 100;
        }

        return [
            'partner_commission' => $partnerPercentage,
            'admin_commission' => round(100 - $partnerPercentage, 2),
            'partner_commission_amount' => $partnerAmount,
            'admin_commission_amount' => $totalAmount - $partnerAmount,
        ];
    }
}
