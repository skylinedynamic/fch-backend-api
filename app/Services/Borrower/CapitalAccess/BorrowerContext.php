<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use Carbon\Carbon;

/**
 * Pre-computes all borrower-derived values needed by the scoring pipeline.
 * Build once per request and pass through all three services — no repeated
 * credit-profile reads or redundant readiness calculations.
 */
class BorrowerContext
{
    public int    $ficoScore;
    public float  $revolvingUtilization;
    public int    $recentInquiries;
    public int    $currentTier;
    public array  $readinessData;
    public int    $readinessScore;
    public ?string $bankingLabel;
    public ?string $tradeLabel;
    public ?string $revenueLabel;

    private function __construct() {}

    /**
     * Build the context from a borrower.
     * ReadinessScoreCalculationService is called exactly once here.
     */
    public static function from(Borrower $borrower): self
    {
        $ctx = new self();

        // --- Credit profile attributes ------------------------------------------
        $ctx->ficoScore           = self::extractFicoScore($borrower);
        $ctx->revolvingUtilization = self::extractRevolvingUtilization($borrower);
        $ctx->recentInquiries     = (int) ($borrower->creditProfile?->recent_inquiries ?? 0);
        $ctx->currentTier         = (int) ($borrower->current_tier ?? 0);

        // --- Readiness (single call) -------------------------------------------
        $ctx->readinessData  = app(ReadinessScoreCalculationService::class)->calculate($borrower);
        $ctx->readinessScore = (int) ($ctx->readinessData['total_score'] ?? 0);

        // --- Factor status labels from readiness --------------------------------
        $ctx->bankingLabel = null;
        $ctx->tradeLabel   = null;
        $ctx->revenueLabel = null;

        if (isset($ctx->readinessData['factors']) && is_array($ctx->readinessData['factors'])) {
            foreach ($ctx->readinessData['factors'] as $factor) {
                $slug = $factor['slug'] ?? '';
                if ($slug === 'banking_relationships') {
                    $ctx->bankingLabel = $factor['status'] ?? null;
                } elseif ($slug === 'tradeline_depth') {
                    $ctx->tradeLabel = $factor['status'] ?? null;
                } elseif ($slug === 'revenue_strength') {
                    $ctx->revenueLabel = $factor['status'] ?? null;
                }
            }
        }

        return $ctx;
    }

    // -------------------------------------------------------------------------
    // Static extractors (used internally and available to callers)
    // -------------------------------------------------------------------------

    public static function extractFicoScore(Borrower $borrower): int
    {
        if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
            return (int) $borrower->creditProfile->fico_score;
        }
        if ($borrower->fico_score !== null) {
            return (int) $borrower->fico_score;
        }
        return 0;
    }

    public static function extractRevolvingUtilization(Borrower $borrower): float
    {
        if ($borrower->creditProfile) {
            $analysis = $borrower->creditProfile->open_accounts_analysis;
            if (is_array($analysis) && isset($analysis['utilization_ratio_grid']['revolving'])) {
                // To the scoring bands' two decimals (…5 | 5.01-10 | …), so a
                // ratio never falls between two bands.
                return round((float) $analysis['utilization_ratio_grid']['revolving'], 2);
            }
        }
        return 0.0;
    }

    /**
     * Count recent delinquencies within a lookback window.
     * Returns [$recent30, $recent60, $recent90, $recent120].
     */
    public static function extractRecentDelinquencies(Borrower $borrower, int $lookbackMonths): array
    {
        $recent30 = $recent60 = $recent90 = $recent120 = 0;
        $cutoffDate = Carbon::now()->subMonths($lookbackMonths);

        if ($borrower->creditProfile) {
            if (!$borrower->creditProfile->relationLoaded('trades')) {
                $borrower->creditProfile->load('trades');
            }

            foreach ($borrower->creditProfile->trades as $trade) {
                $isRecent = false;
                if ($trade->max_delinquency_date && strcasecmp($trade->max_delinquency_date, 'N/A') !== 0) {
                    try {
                        if (Carbon::parse($trade->max_delinquency_date)->greaterThanOrEqualTo($cutoffDate)) {
                            $isRecent = true;
                        }
                    } catch (\Throwable) {
                        // unparseable date — skip
                    }
                }

                if ($isRecent) {
                    $str = $trade->delinquencies_30_60_90;
                    if ($str && $str !== 'N/A' && $str !== '-/-/-' && $str !== '-/-/-/-') {
                        $parts = explode('/', $str);
                        if (count($parts) >= 3) {
                            $recent30  += (int) $parts[0];
                            $recent60  += (int) $parts[1];
                            $recent90  += (int) $parts[2];
                            $recent120 += (int) ($parts[3] ?? 0);
                        }
                    }
                }
            }
        }

        return [$recent30, $recent60, $recent90, $recent120];
    }
}
