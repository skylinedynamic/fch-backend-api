<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ApprovalOddsService
{
    /**
     * Calculate the approval odds for a specific capital access sequence (roadmap card).
     *
     * No late-payment delinquency checks or modifiers — only FICO gate, utilization
     * gate, difficulty modifier, and fit modifiers.
     *
     * When $ctx and $oddsTiers are pre-built by the orchestrator this method fires
     * zero DB queries. Backward-compatible: all extra params are optional.
     *
     * @param Borrower              $borrower
     * @param CapitalAccessSequence $seq       Row from capital_access_sequences
     * @param CapitalAccess         $play
     * @param BorrowerContext|null  $ctx       Pre-built context (readiness already computed)
     * @param Collection|null       $oddsTiers Tier rows for MODEL_APPROVAL_ODDS, grouped by factor_slug
     * @return array
     */
    public function calculate(
        Borrower $borrower,
        CapitalAccessSequence $seq,
        CapitalAccess $play,
        ?BorrowerContext $ctx = null,
        ?Collection $oddsTiers = null
    ): array {
        Log::info("ApprovalOddsService::calculate - Starting", [
            'borrower_id' => $borrower->id,
            'sequence_id' => $seq->id ?? null,
            'play_id'     => $play->id,
        ]);

        // Build context / load tiers if not pre-supplied
        if ($ctx === null) {
            $ctx = BorrowerContext::from($borrower);
        }
        if ($oddsTiers === null) {
            $oddsTiers = app(ScoreConfigurationCache::class)->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_ODDS);
        }

        // 1. Eligibility gate (FICO + utilization)
        $lockStatus = $this->getLockStatus($ctx, $seq);
        if ($lockStatus !== null) {
            return $lockStatus;
        }

        // 2. Base = readiness score + card difficulty modifier
        $difficultyModifier = $seq->difficulty_modifier !== null ? (int) $seq->difficulty_modifier : 0;
        $odds = $ctx->readinessScore + $difficultyModifier;

        Log::info("ApprovalOddsService::calculate - Base odds", [
            'readiness_score'     => $ctx->readinessScore,
            'difficulty_modifier' => $difficultyModifier,
            'base_odds'           => $odds,
        ]);

        // 3. FICO above card minimum
        $minFico   = $seq->minimum_fico_requirement !== null ? (int) $seq->minimum_fico_requirement : 0;
        $fico_diff = max(0, $ctx->ficoScore - $minFico);
        $ficoPoints = $this->matchTier($oddsTiers->get('fico_above_minimum') ?? collect(), $fico_diff);

        // 4. Utilization
        $utilPoints = $this->matchTier($oddsTiers->get('utilization') ?? collect(), $ctx->revolvingUtilization);

        // 5. Recent inquiries
        $inquiryPoints = $this->matchTier($oddsTiers->get('recent_inquiries') ?? collect(), $ctx->recentInquiries);

        // 6. Banking relationship (label-based fit modifier)
        $bankProbLabel = $this->mapBankingLabel($ctx->bankingLabel);
        $bankPoints    = (int) (($oddsTiers->get('banking_relationship') ?? collect())->firstWhere('label', $bankProbLabel)?->numeric_value ?? 0);

        // 7. Tradeline depth (label-based fit modifier)
        $tradeProbLabel = $this->mapTradelineLabel($ctx->tradeLabel);
        $tradePoints    = (int) (($oddsTiers->get('tradeline_depth') ?? collect())->firstWhere('label', $tradeProbLabel)?->numeric_value ?? 0);

        // 8. Revenue / Income (label-based fit modifier)
        $revProbLabel = $this->mapRevenueLabel($ctx->revenueLabel);
        $revPoints    = (int) (($oddsTiers->get('revenue_income') ?? collect())->firstWhere('label', $revProbLabel)?->numeric_value ?? 0);

        $odds += $ficoPoints + $utilPoints + $inquiryPoints + $bankPoints + $tradePoints + $revPoints;

        Log::info("ApprovalOddsService::calculate - Factor points", [
            'fico_points'    => $ficoPoints,
            'util_points'    => $utilPoints,
            'inquiry_points' => $inquiryPoints,
            'bank_points'    => $bankPoints,
            'trade_points'   => $tradePoints,
            'rev_points'     => $revPoints,
            'intermediate'   => $odds,
        ]);

        $finalOdds = max(0, min(99, (int) round($odds)));

        Log::info("ApprovalOddsService::calculate - Final odds", [
            'raw_odds'   => $odds,
            'final_odds' => $finalOdds,
        ]);

        return [
            'probability' => $finalOdds,
            'status'      => true,
            'reason_code' => null,
        ];
    }

    // =========================================================================
    // Lock-status check (uses pre-computed context — no DB)
    // =========================================================================

    private function getLockStatus(BorrowerContext $ctx, CapitalAccessSequence $seq): ?array
    {
        $minFico = $seq->minimum_fico_requirement !== null ? (int) $seq->minimum_fico_requirement : 0;
        if ($minFico > $ctx->ficoScore) {
            Log::info("ApprovalOddsService::getLockStatus - Locked: FICO_BELOW_MINIMUM");
            return $this->disqualify('FICO_BELOW_MINIMUM');
        }

        $maxUtil = $seq->maximum_utilization_threshold !== null ? (float) $seq->maximum_utilization_threshold : 100.0;
        if ($maxUtil < $ctx->revolvingUtilization) {
            Log::info("ApprovalOddsService::getLockStatus - Locked: UTILIZATION_ABOVE_MAXIMUM");
            return $this->disqualify('UTILIZATION_ABOVE_MAXIMUM');
        }

        return null;
    }

    // =========================================================================
    // Helpers — shared with ProbabilityService (no duplication needed)
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
            ScoreConfiguration::STATUS_BANKING_STRONG   => ScoreConfiguration::PROB_STATUS_BANKING_STRONG,
            ScoreConfiguration::STATUS_BANKING_MODERATE => ScoreConfiguration::PROB_STATUS_BANKING_MODERATE,
            ScoreConfiguration::STATUS_BANKING_LIMITED  => ScoreConfiguration::PROB_STATUS_BANKING_LIMITED,
            default                                     => ScoreConfiguration::PROB_STATUS_BANKING_NONE,
        };
    }

    private function mapTradelineLabel(?string $label): string
    {
        return match ($label) {
            ScoreConfiguration::STATUS_TRADE_MULTIPLE_HIGH => ScoreConfiguration::PROB_STATUS_TRADE_MULTIPLE_HIGH,
            ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH    => ScoreConfiguration::PROB_STATUS_TRADE_GOOD_DEPTH,
            ScoreConfiguration::STATUS_TRADE_MODERATE      => ScoreConfiguration::PROB_STATUS_TRADE_MODERATE,
            default                                        => ScoreConfiguration::PROB_STATUS_TRADE_THIN,
        };
    }

    private function mapRevenueLabel(?string $label): string
    {
        return match ($label) {
            ScoreConfiguration::STATUS_REVENUE_STRONG   => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG,
            ScoreConfiguration::STATUS_REVENUE_MODERATE => ScoreConfiguration::PROB_STATUS_REVENUE_MODERATE,
            ScoreConfiguration::STATUS_REVENUE_LIMITED  => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED,
            default                                     => ScoreConfiguration::PROB_STATUS_REVENUE_STARTUP,
        };
    }

    private function disqualify(string $reasonCode): array
    {
        Log::info("ApprovalOddsService::disqualify", ['reason_code' => $reasonCode]);
        return ['probability' => 0, 'status' => false, 'reason_code' => $reasonCode];
    }
}
