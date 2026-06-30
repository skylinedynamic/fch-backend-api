<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ApprovalProbabilityService
{
    /**
     * Calculate the approval probability.
     *
     * When $ctx and $probTiers/$modifiers are pre-built by the orchestrator,
     * this method fires zero DB queries. Backward-compatible: all params are optional.
     *
     * @param Borrower           $borrower
     * @param CapitalAccess      $play
     * @param BorrowerContext|null $ctx        Pre-built context (readiness already computed)
     * @param Collection|null    $probTiers   Tier rows for MODEL_APPROVAL_PROBABILITY, grouped by factor_slug
     * @param Collection|null    $modifiers   Late-payment modifier rows, keyed by factor_slug
     * @return array
     */
    public function calculate(
        Borrower $borrower,
        CapitalAccess $play,
        ?BorrowerContext $ctx = null,
        ?Collection $probTiers = null,
        ?Collection $modifiers = null
    ): array {
        Log::info("ApprovalProbabilityService::calculate - Starting calculation", [
            'borrower_id' => $borrower->id,
            'play_id' => $play->id,
            'play_name' => $play->name ?? '',
        ]);

        // Build context on-the-fly if not supplied (standalone / backward-compat)
        if ($ctx === null) {
            $ctx = BorrowerContext::from($borrower);
        }

        // Load tiers / modifiers if not supplied
        if ($probTiers === null || $modifiers === null) {
            $cache = app(ScoreConfigurationCache::class);
            $probTiers = $probTiers ?? $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $modifiers = $modifiers ?? $cache->getModifiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
        }

        // 1. Lock-status check (uses pre-computed context values)
        $lockStatus = $this->getLockStatus($borrower, $play, $ctx);
        if ($lockStatus !== null) {
            return $lockStatus;
        }

        // 2. Base: readiness score + strategy difficulty modifier
        $probability = $ctx->readinessScore + ($play->difficulty_modifier ?? 0);

        Log::info("ApprovalProbabilityService::calculate - Base probability", [
            'readiness_score' => $ctx->readinessScore,
            'difficulty_modifier' => $play->difficulty_modifier,
            'base_probability' => $probability,
        ]);

        // 3. FICO above play minimum
        $fico_diff = max(0, $ctx->ficoScore - ((int) ($play->minimum_fico_requirement ?? 0)));
        $ficoPoints = $this->matchTier($probTiers->get('fico_above_minimum') ?? collect(), $fico_diff);

        // 4. Utilization
        $utilPoints = $this->matchTier($probTiers->get('utilization') ?? collect(), $ctx->revolvingUtilization);

        // 5. Recent inquiries
        $inquiryPoints = $this->matchTier($probTiers->get('recent_inquiries') ?? collect(), $ctx->recentInquiries);

        // 6. Banking relationship (label-based)
        $bankProbLabel = $this->mapBankingLabel($ctx->bankingLabel);
        $bankPoints = (int) (($probTiers->get('banking_relationship') ?? collect())->firstWhere('label', $bankProbLabel)?->numeric_value ?? 0);

        // 7. Tradeline depth (label-based)
        $tradeProbLabel = $this->mapTradelineLabel($ctx->tradeLabel);
        $tradePoints = (int) (($probTiers->get('tradeline_depth') ?? collect())->firstWhere('label', $tradeProbLabel)?->numeric_value ?? 0);

        // 8. Revenue / Income (label-based)
        $revProbLabel = $this->mapRevenueLabel($ctx->revenueLabel);
        $revPoints = (int) (($probTiers->get('revenue_income') ?? collect())->firstWhere('label', $revProbLabel)?->numeric_value ?? 0);

        $probability += $ficoPoints + $utilPoints + $inquiryPoints + $bankPoints + $tradePoints + $revPoints;

        Log::info("ApprovalProbabilityService::calculate - Subscore points", [
            'fico_points' => $ficoPoints,
            'util_points' => $utilPoints,
            'inquiry_points' => $inquiryPoints,
            'bank_points' => $bankPoints,
            'trade_points' => $tradePoints,
            'rev_points' => $revPoints,
            'intermediate' => $probability,
        ]);

        $finalProbability = max(0, min(99, (int) round($probability)));

        Log::info("ApprovalProbabilityService::calculate - Final probability", [
            'raw_probability' => $probability,
            'final_probability' => $finalProbability,
        ]);

        return [
            'probability' => $finalProbability,
            'status' => true,
            'reason_code' => null,
        ];
    }

    // =========================================================================
    // Lock-status check
    // =========================================================================

    private function getLockStatus(Borrower $borrower, CapitalAccess $play, BorrowerContext $ctx): ?array
    {
        // FICO
        $minFico = (int) ($play->minimum_fico_requirement ?? 0);
        if ($minFico > $ctx->ficoScore) {
            return $this->disqualify('FICO_BELOW_MINIMUM');
        }

        // Utilization
        $maxUtil = (float) ($play->maximum_utilization_threshold ?? 100.0);
        if ($maxUtil < $ctx->revolvingUtilization) {
            return $this->disqualify('UTILIZATION_ABOVE_MAXIMUM');
        }

        // Tier
        if ($ctx->currentTier < 2) {
            return $this->disqualify('TIER_BELOW_MINIMUM');
        }

        // Recent delinquencies
        if (!$play->allow_recent_late_payment) {
            $lookbackMonths = $play->late_payment_lookback_months !== null ? (int) $play->late_payment_lookback_months : 12;
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

    /** Match a numeric value against a sorted tier collection, return points */
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
        Log::info("ApprovalProbabilityService::disqualify", ['reason_code' => $reasonCode]);
        return ['probability' => 0, 'status' => false, 'reason_code' => $reasonCode];
    }
}
