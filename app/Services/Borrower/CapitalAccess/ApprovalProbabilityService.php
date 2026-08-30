<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ApprovalProbabilityService
{
    /**
     * Calculate the approval probability.
     *
     * When $ctx, $probTiers/$modifiers, $delayDays and $latestCompletedPlay are
     * all pre-built by the orchestrator this method fires ZERO additional DB queries.
     * All params remain optional for backward-compatibility.
     *
     * @param Borrower                       $borrower
     * @param CapitalAccess                  $play
     * @param BorrowerContext|null           $ctx                 Pre-built context
     * @param Collection|null                $probTiers           Tier rows grouped by factor_slug
     * @param Collection|null                $modifiers           Late-payment modifier rows
     * @param bool                           $skipDelayCheck      Skip Next Play Delay gate
     * @param int                            $delayDays           Pre-loaded delay days (-1 = load lazily from cache)
     * @param BorrowerCapitalAccessPlay|null $latestCompletedPlay Pre-loaded or null
     * @return array
     */
    public function calculate(
        Borrower $borrower,
        CapitalAccess $play,
        ?BorrowerContext $ctx = null,
        ?Collection $probTiers = null,
        ?Collection $modifiers = null,
        bool $skipDelayCheck = false,
        int $delayDays = -1,
        ?BorrowerCapitalAccessPlay $latestCompletedPlay = null
    ): array {

        // Build context on-the-fly if not supplied
        if ($ctx === null) {
            $ctx = BorrowerContext::from($borrower);
        }

        // Load tiers / modifiers if not supplied
        if ($probTiers === null || $modifiers === null) {
            $cache = app(ScoreConfigurationCache::class);
            $probTiers = $probTiers ?? $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $modifiers = $modifiers ?? $cache->getModifiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
        }

        // 1. Hard disqualification checks (zero DB queries — uses pre-computed ctx)
        $lockStatus = $this->getLockStatus($borrower, $play, $ctx);
        if ($lockStatus !== null) {
            return $lockStatus;
        }

        // 2. Base probability
        $probability = $ctx->readinessScore + ($play->difficulty_modifier ?? 0);

        // 3. Factor subscores
        $fico_diff = max(0, $ctx->ficoScore - ((int) ($play->minimum_fico_requirement ?? 0)));
        $ficoPoints = $this->matchTier($probTiers->get('fico_above_minimum') ?? collect(), $fico_diff);
        $utilPoints = $this->matchTier($probTiers->get('utilization') ?? collect(), $ctx->revolvingUtilization);
        $inquiryPoints = $this->matchTier($probTiers->get('recent_inquiries') ?? collect(), $ctx->recentInquiries);

        $bankProbLabel = $this->mapBankingLabel($ctx->bankingLabel);
        $bankPoints = (int) (($probTiers->get('banking_relationship') ?? collect())->firstWhere('label', $bankProbLabel)?->numeric_value ?? 0);

        $tradeProbLabel = $this->mapTradelineLabel($ctx->tradeLabel);
        $tradePoints = (int) (($probTiers->get('tradeline_depth') ?? collect())->firstWhere('label', $tradeProbLabel)?->numeric_value ?? 0);

        $revProbLabel = $this->mapRevenueLabel($ctx->revenueLabel);
        $revPoints = (int) (($probTiers->get('revenue_income') ?? collect())->firstWhere('label', $revProbLabel)?->numeric_value ?? 0);

        $probability += $ficoPoints + $utilPoints + $inquiryPoints + $bankPoints + $tradePoints + $revPoints;

        $finalProbability = max(0, min(99, (int) round($probability)));

        // 4. Next Play Delay gate — only when not skipped and delay > 0
        $nextPlayDelayActive = false;
        $unlockAt = null;

        if (!$skipDelayCheck) {
            // Lazy-load from ScoreConfigurationCache when not pre-supplied by orchestrator
            if ($delayDays < 0) {
                $delayDays = app(ScoreConfigurationCache::class)->getNextPlayDelayDays();
            }

            if ($delayDays > 0) {
                // Skip delay if borrower has already started this specific play
                $hasStartedCurrent = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                    ->where('capital_access_id', $play->id)
                    ->exists();

                if (!$hasStartedCurrent) {
                    // Use pre-loaded latest completed play when supplied (zero extra query)
                    if ($latestCompletedPlay === null) {
                        $latestCompletedPlay = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                            ->where('status', BorrowerCapitalAccessPlay::STATUS_COMPLETED)
                            ->where('capital_access_id', '!=', $play->id)
                            ->orderBy('updated_at', 'desc')
                            ->first();
                    }

                    if ($latestCompletedPlay) {
                        $calcUnlockAt = $latestCompletedPlay->updated_at->copy()->addDays($delayDays);
                        if (now()->lessThan($calcUnlockAt)) {
                            $nextPlayDelayActive = true;
                            $unlockAt = $calcUnlockAt->toIso8601String();
                        }
                    }
                }
            }
        }

        return [
            'probability' => $finalProbability,
            'status' => true,
            'reason_code' => $nextPlayDelayActive ? 'NEXT_PLAY_DELAY_ACTIVE' : null,
            'unlock_at' => $unlockAt,
        ];
    }

    // =========================================================================
    // Hard disqualification checks — delay logic lives in calculate() only
    // =========================================================================

    private function getLockStatus(Borrower $borrower, CapitalAccess $play, BorrowerContext $ctx): ?array
    {
        $minFico = (int) ($play->minimum_fico_requirement ?? 0);
        if ($minFico > $ctx->ficoScore) {
            return $this->disqualify('FICO_BELOW_MINIMUM');
        }

        $maxUtil = (float) ($play->maximum_utilization_threshold ?? 100.0);
        if ($maxUtil < $ctx->revolvingUtilization) {
            return $this->disqualify('UTILIZATION_ABOVE_MAXIMUM');
        }

        if ($ctx->currentTier < 2) {
            return $this->disqualify('TIER_BELOW_MINIMUM');
        }

        if (!$play->allow_recent_late_payment) {
            $lookbackMonths = $play->late_payment_lookback_months !== null
                ? (int) $play->late_payment_lookback_months
                : 12;
            [$r30, $r60, $r90, $r120] = BorrowerContext::extractRecentDelinquencies($borrower, $lookbackMonths);
            if ($r30 > 0 || $r60 > 0 || $r90 > 0 || $r120 > 0) {
                return $this->disqualify('RECENT_LATE_PAYMENTS');
            }
        }

        return null;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function matchTier(Collection $tiers, float $value): int
    {
        foreach ($tiers as $tier) {
            $minOk = $tier->min_value === null || $value >= (float) $tier->min_value;
            $maxOk = $tier->max_value === null || $value <= (float) $tier->max_value;
            if ($minOk && $maxOk) {
                return (int) $tier->numeric_value;
            }
        }
        return 0;
    }

    private function mapBankingLabel(?string $label): string
    {
        return match ($label) {
            ScoreConfiguration::STATUS_BANKING_STRONG => ScoreConfiguration::PROB_STATUS_BANKING_STRONG,
            ScoreConfiguration::STATUS_BANKING_MODERATE => ScoreConfiguration::PROB_STATUS_BANKING_MODERATE,
            ScoreConfiguration::STATUS_BANKING_LIMITED => ScoreConfiguration::PROB_STATUS_BANKING_LIMITED,
            default => ScoreConfiguration::PROB_STATUS_BANKING_NONE,
        };
    }

    private function mapTradelineLabel(?string $label): string
    {
        return match ($label) {
            ScoreConfiguration::STATUS_TRADE_MULTIPLE_HIGH => ScoreConfiguration::PROB_STATUS_TRADE_MULTIPLE_HIGH,
            ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH => ScoreConfiguration::PROB_STATUS_TRADE_GOOD_DEPTH,
            ScoreConfiguration::STATUS_TRADE_MODERATE => ScoreConfiguration::PROB_STATUS_TRADE_MODERATE,
            default => ScoreConfiguration::PROB_STATUS_TRADE_THIN,
        };
    }

    private function mapRevenueLabel(?string $label): string
    {
        return match ($label) {
            ScoreConfiguration::STATUS_REVENUE_STRONG => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG,
            ScoreConfiguration::STATUS_REVENUE_MODERATE => ScoreConfiguration::PROB_STATUS_REVENUE_MODERATE,
            ScoreConfiguration::STATUS_REVENUE_LIMITED => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED,
            default => ScoreConfiguration::PROB_STATUS_REVENUE_STARTUP,
        };
    }

    private function disqualify(string $reasonCode): array
    {
        return ['probability' => 0, 'status' => false, 'reason_code' => $reasonCode, 'unlock_at' => null];
    }
}
