<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Common\ScoreConfiguration;

class ApprovalProbabilityService
{
    /**
     * Calculate the approval probability for a borrower and a specific capital access play.
     *
     * @param Borrower $borrower
     * @param CapitalAccess $play
     * @return array
     */
    public function calculate(Borrower $borrower, CapitalAccess $play): array
    {
        // 1. Check lock status first
        $lockStatus = $this->getLockStatus($borrower, $play);
        if ($lockStatus !== null) {
            return $lockStatus;
        }

        // 3. Calculate probability if eligible
        $baseProbability = 95;
        $readiness_data = app(ReadinessScoreCalculationService::class)->calculate($borrower);
        $readiness_score = (int) ($readiness_data['total_score'] ?? 0);
        $score_after_difficulty_modifie = $readiness_score + $play->difficulty_modifier ?? 0;
        $probability = $score_after_difficulty_modifie;

        // Fetch new approval probability factor tiers
        $probTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_APPROVAL_PROBABILITY)
            ->get()
            ->groupBy('factor_slug');

        // 1. FICO Above Play Minimum
        $fico = $this->getFicoScore($borrower);
        $minFico = $play->minimum_fico_requirement !== null ? (int) $play->minimum_fico_requirement : 0;
        $fico_diff = max(0, $fico - $minFico);
        $ficoTiers = $probTiers->get('fico_above_minimum') ?? collect();
        $ficoPoints = 0;
        foreach ($ficoTiers as $tier) {
            $minMatched = ($tier->min_value === null || $fico_diff >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $fico_diff <= (float) $tier->max_value);
            if ($minMatched && $maxMatched) {
                $ficoPoints = (int) $tier->numeric_value;
                break;
            }
        }

        // 2. Utilization
        $utilization = $this->getRevolvingUtilization($borrower);
        $utilTiers = $probTiers->get('utilization') ?? collect();
        $utilPoints = 0;
        foreach ($utilTiers as $tier) {
            $minMatched = ($tier->min_value === null || $utilization >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $utilization <= (float) $tier->max_value);
            if ($minMatched && $maxMatched) {
                $utilPoints = (int) $tier->numeric_value;
                break;
            }
        }

        // 3. Recent Inquiries
        $inquiriesCount = $borrower->creditProfile?->recent_inquiries ?? 0;
        $inquiryTiers = $probTiers->get('recent_inquiries') ?? collect();
        $inquiryPoints = 0;
        foreach ($inquiryTiers as $tier) {
            $minMatched = ($tier->min_value === null || $inquiriesCount >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $inquiriesCount <= (float) $tier->max_value);
            if ($minMatched && $maxMatched) {
                $inquiryPoints = (int) $tier->numeric_value;
                break;
            }
        }

        // Extract status labels and raw scores from readiness calculation
        $bankingRawScore = 0;
        $tradeLabel = null;
        $revenueLabel = null;

        if (isset($readiness_data['factors']) && is_array($readiness_data['factors'])) {
            foreach ($readiness_data['factors'] as $factor) {
                $slug = $factor['slug'] ?? '';
                if ($slug === 'banking_relationships') {
                    $bankingRawScore = (float) ($factor['raw_score'] ?? 0);
                } elseif ($slug === 'tradeline_depth') {
                    $tradeLabel = $factor['value'] ?? null; // e.g. 'Thin File', 'Moderate Depth', etc.
                } elseif ($slug === 'revenue_strength') {
                    $revenueLabel = $factor['value'] ?? null; // e.g. 'Strong', 'Moderate', 'Limited', 'Weak'
                }
            }
        }

        // 4. Banking Relationship
        // Map raw_score → readiness STATUS constant → probability tier label constant → points
        $bankTiers = $probTiers->get('banking_relationship') ?? collect();
        if ($bankingRawScore >= 100) {
            $bankProbLabel = ScoreConfiguration::PROB_STATUS_BANKING_STRONG;
        } elseif ($bankingRawScore >= 75) {
            $bankProbLabel = ScoreConfiguration::PROB_STATUS_BANKING_MODERATE;
        } else {
            $bankProbLabel = ScoreConfiguration::PROB_STATUS_BANKING_NONE;
        }
        $bankPoints = (int) ($bankTiers->firstWhere('label', $bankProbLabel)?->numeric_value ?? 0);

        // 5. Tradeline Depth
        // Map readiness tier label (STATUS_TRADE_*) → probability tier label (PROB_STATUS_TRADE_*) → points
        $tradeTiers = $probTiers->get('tradeline_depth') ?? collect();
        $tradeProbLabelMap = [
            ScoreConfiguration::STATUS_TRADE_MULTIPLE_HIGH => ScoreConfiguration::PROB_STATUS_TRADE_HIGH,
            ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH    => ScoreConfiguration::PROB_STATUS_TRADE_HIGH,
            ScoreConfiguration::STATUS_TRADE_MODERATE      => ScoreConfiguration::PROB_STATUS_TRADE_MODERATE,
            ScoreConfiguration::STATUS_TRADE_THIN          => ScoreConfiguration::PROB_STATUS_TRADE_THIN,
        ];
        $tradeProbLabel = $tradeProbLabelMap[$tradeLabel] ?? ScoreConfiguration::PROB_STATUS_TRADE_THIN;
        $tradePoints = (int) ($tradeTiers->firstWhere('label', $tradeProbLabel)?->numeric_value ?? -10);

        // 6. Revenue / Income
        // Map readiness tier label (STATUS_REVENUE_*) → probability tier label (PROB_STATUS_REVENUE_*) → points
        // Special case: startup with no revenue (null label + business < 24 months) maps to STARTUP tier
        $revTiers = $probTiers->get('revenue_income') ?? collect();
        $annualRevenue = $borrower->annual_revenue !== null ? (float) $borrower->annual_revenue : 0;
        $months = $borrower->business_start_date ? \Carbon\Carbon::parse($borrower->business_start_date)->diffInMonths() : 0;
        $isStartupNoRevenue = ($annualRevenue == 0 && $months <= 24);

        if ($isStartupNoRevenue) {
            $revProbLabel = ScoreConfiguration::PROB_STATUS_REVENUE_STARTUP;
        } else {
            $revProbLabelMap = [
                ScoreConfiguration::STATUS_REVENUE_STRONG   => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG,
                ScoreConfiguration::STATUS_REVENUE_MODERATE => ScoreConfiguration::PROB_STATUS_REVENUE_MODERATE,
                ScoreConfiguration::STATUS_REVENUE_LIMITED  => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED,
                ScoreConfiguration::STATUS_REVENUE_WEAK     => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED,
            ];
            $revProbLabel = $revProbLabelMap[$revenueLabel] ?? ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED;
        }
        $revPoints = (int) ($revTiers->firstWhere('label', $revProbLabel)?->numeric_value ?? 0);

        $probability += $ficoPoints + $utilPoints + $inquiryPoints + $bankPoints + $tradePoints + $revPoints;

        // Apply Late Payment Modifiers
        $dbModifiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_LATE_PAYMENT_MODIFIER)
            ->where('model_slug', ScoreConfiguration::MODEL_APPROVAL_PROBABILITY)
            ->get()
            ->keyBy('factor_slug');

        $late30_1_qty = (int) ($dbModifiers->get('late_30_1')?->min_value ?? 1);
        $late30_1_val = (int) ($dbModifiers->get('late_30_1')?->numeric_value ?? -5);

        $late30_2_qty = (int) ($dbModifiers->get('late_30_2_plus')?->min_value ?? 2);
        $late30_2_val = (int) ($dbModifiers->get('late_30_2_plus')?->numeric_value ?? -10);

        $late60_1_qty = (int) ($dbModifiers->get('late_60_1')?->min_value ?? 1);
        $late60_1_val = (int) ($dbModifiers->get('late_60_1')?->numeric_value ?? -15);

        $late60_2_qty = (int) ($dbModifiers->get('late_60_2_plus')?->min_value ?? 2);
        $late60_2_val = (int) ($dbModifiers->get('late_60_2_plus')?->numeric_value ?? -25);

        $late90_1_qty = (int) ($dbModifiers->get('late_90_1')?->min_value ?? 1);
        $late90_1_val = (int) ($dbModifiers->get('late_90_1')?->numeric_value ?? -30);

        $late90_2_qty = (int) ($dbModifiers->get('late_90_2_plus')?->min_value ?? 2);
        $late90_2_val = (int) ($dbModifiers->get('late_90_2_plus')?->numeric_value ?? -50);

        $lookbackMonths = $play->late_payment_lookback_months !== null ? (int) $play->late_payment_lookback_months : 12;
        [$recent30, $recent60, $recent90] = $this->getRecentDelinquencies($borrower, $lookbackMonths);

        $latePaymentModifier = 0;
        $totalLates = $recent30 + $recent60 + $recent90;

        if ($totalLates === 0) {
            $latePaymentModifier += 5;
        } else {
            // 30-day lates
            if ($recent30 >= $late30_2_qty) {
                $latePaymentModifier += $late30_2_val;
            } elseif ($recent30 >= $late30_1_qty) {
                $latePaymentModifier += $late30_1_val;
            }

            // 60-day lates
            if ($recent60 >= $late60_2_qty) {
                $latePaymentModifier += $late60_2_val;
            } elseif ($recent60 >= $late60_1_qty) {
                $latePaymentModifier += $late60_1_val;
            }

            // 90-day lates
            if ($recent90 >= $late90_2_qty) {
                $latePaymentModifier += $late90_2_val;
            } elseif ($recent90 >= $late90_1_qty) {
                $latePaymentModifier += $late90_1_val;
            }
        }

        $probability += $latePaymentModifier;

        // Capped between 0 and 99
        $finalProbability = (int) round($probability);
        if ($finalProbability < 0) {
            $finalProbability = 0;
        } elseif ($finalProbability > 99) {
            $finalProbability = 99;
        }

        return [
            'probability' => $finalProbability,
            'status' => true,
            'reason_code' => null,
            'ReasonCode' => null,
        ];
    }

    /**
     * Check if the borrower is locked (disqualified) for the strategy.
     *
     * @param Borrower $borrower
     * @param CapitalAccess $play
     * @return array|null Returns the disqualified response array if locked, or null if eligible.
     */
    private function getLockStatus(Borrower $borrower, CapitalAccess $play): ?array
    {
        // 1. FICO requirement check
        $fico = $this->getFicoScore($borrower);
        $minFico = $play->minimum_fico_requirement !== null ? (int) $play->minimum_fico_requirement : 0;
        if ($minFico > $fico) {
            return $this->disqualify('FICO_BELOW_MINIMUM');
        }

        // 2. Revolving utilization check
        $utilization = $this->getRevolvingUtilization($borrower);
        $maxUtil = $play->maximum_utilization_threshold !== null ? (float) $play->maximum_utilization_threshold : 100.0;
        if ($maxUtil < $utilization) {
            return $this->disqualify('UTILIZATION_ABOVE_MAXIMUM');
        }

        // 3. Current tier requirement check
        $currentTierNumber = $borrower->current_tier;
        if ($currentTierNumber < 2) {
            return $this->disqualify('TIER_BELOW_MINIMUM');
        }

        // 4. Recent delinquencies lookback check
        $lookbackMonths = $play->late_payment_lookback_months !== null ? (int) $play->late_payment_lookback_months : 12;
        [$recent30, $recent60, $recent90] = $this->getRecentDelinquencies($borrower, $lookbackMonths);

        $allowRecentLate = (bool) $play->allow_recent_late_payment;
        if ($allowRecentLate === false && ($recent30 > 0 || $recent60 > 0 || $recent90 > 0)) {
            return $this->disqualify('RECENT_LATE_PAYMENTS');
        }

        return null;
    }

    /**
     * Get borrower FICO score.
     *
     * @param Borrower $borrower
     * @return int
     */
    private function getFicoScore(Borrower $borrower): int
    {
        $fico = null;
        if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
            $fico = (int) $borrower->creditProfile->fico_score;
        } elseif ($borrower->fico_score !== null) {
            $fico = (int) $borrower->fico_score;
        }
        return $fico !== null ? $fico : 0;
    }

    /**
     * Get borrower revolving utilization ratio.
     *
     * @param Borrower $borrower
     * @return float
     */
    private function getRevolvingUtilization(Borrower $borrower): float
    {
        $utilization = null;
        if ($borrower->creditProfile) {
            $analysis = $borrower->creditProfile->open_accounts_analysis;
            if (is_array($analysis) && isset($analysis['utilization_ratio_grid']['revolving'])) {
                $utilization = (float) $analysis['utilization_ratio_grid']['revolving'];
            }
        }
        return $utilization !== null ? $utilization : 0.0;
    }

    /**
     * Get borrower recent delinquencies counts within lookback period.
     *
     * @param Borrower $borrower
     * @param int $lookbackMonths
     * @return array Array of [$recent30, $recent60, $recent90]
     */
    private function getRecentDelinquencies(Borrower $borrower, int $lookbackMonths): array
    {
        $recent30 = 0;
        $recent60 = 0;
        $recent90 = 0;

        $cutoffDate = \Carbon\Carbon::now()->subMonths($lookbackMonths);

        if ($borrower->creditProfile) {
            if (!$borrower->creditProfile->relationLoaded('trades')) {
                $borrower->creditProfile->load('trades');
            }

            foreach ($borrower->creditProfile->trades as $trade) {
                $isRecent = false;
                if ($trade->max_delinquency_date && strcasecmp($trade->max_delinquency_date, 'N/A') !== 0) {
                    try {
                        $delinqDate = \Carbon\Carbon::parse($trade->max_delinquency_date);
                        if ($delinqDate->greaterThanOrEqualTo($cutoffDate)) {
                            $isRecent = true;
                        }
                    } catch (\Throwable $e) {
                        // fallback if parsing fails
                    }
                }

                if ($isRecent) {
                    $delinqStr = $trade->delinquencies_30_60_90;
                    if ($delinqStr && $delinqStr !== 'N/A' && $delinqStr !== '-/-/-') {
                        $parts = explode('/', $delinqStr);
                        if (count($parts) === 3) {
                            $recent30 += (int) $parts[0];
                            $recent60 += (int) $parts[1];
                            $recent90 += (int) $parts[2];
                        }
                    }
                }
            }
        }

        return [$recent30, $recent60, $recent90];
    }

    /**
     * Helper to return consistent disqualified array structure.
     *
     * @param string $reasonCode
     * @return array
     */
    private function disqualify(string $reasonCode): array
    {
        return [
            'probability' => 0,
            'status' => false,
            'reason_code' => $reasonCode,
            'ReasonCode' => $reasonCode,
        ];
    }
}
