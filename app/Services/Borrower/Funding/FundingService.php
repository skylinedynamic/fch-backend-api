<?php

namespace App\Services\Borrower\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Lender\LenderProductCategory;
use App\Services\Borrower\CapitalAccess\ReadinessScoreCalculationService;
use Illuminate\Support\Facades\Log;

class FundingService
{
    /**
     * Get grouped funding sequencings for the borrower portal.
     * We only return Active groups and their Active sequences that pass visibility logic.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGroupedSequencings($search = null, $groupId = null)
    {
        $query = FundingGroup::with(['sequencings' => function ($query) use ($search) {
            // Eager-load the type relation so engine_status can check type.status
            $query->with('type')
                  ->where('status', FundingSequencing::STATUS_ACTIVE)
                  ->orderBy('sort_order', 'asc');
                  
            if (!empty($search)) {
                $query->search($search);
            }
        }])->where('status', FundingGroup::STATUS_ACTIVE);
        
        if (!empty($groupId) && $groupId !== 'all') {
            $query->where('id', $groupId);
        }

        $query->orderBy('sort_order', 'asc');

        $groups = $query->get();

        // Filter out HIDDEN sequences (e.g., ones with no lenders)
        foreach ($groups as $group) {
            $group->setRelation('sequencings', $group->sequencings->filter(function ($seq) {
                return $seq->engine_status !== FundingSequencing::ENGINE_STATUS_HIDDEN;
            })->values());
        }

        // Remove groups that are now empty
        $groups = $groups->filter(function ($group) {
            return $group->sequencings->isNotEmpty();
        })->values();

        return $groups;
    }



    /**
     * Get all active funding types for the tab navigation.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveTypes()
    {
        return LenderProductCategory::where('status', LenderProductCategory::STATUS_ACTIVE)->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Get dynamic dashboard stats derived from the filtered FundingSequencing catalog and Borrower profile.
     *
     * @param \Illuminate\Database\Eloquent\Collection $groups
     * @param \App\Models\Borrower\Borrower $borrower
     * @return array
     */
    public function getDashboardStats($groups, $borrower = null): array
    {
        $fundingReadinessScore = 'N/A';
        if ($borrower) {
            $readinessService = app(ReadinessScoreCalculationService::class);
            $readinessResult = $readinessService->calculate($borrower);
            $fundingReadinessScore = $readinessResult['total_score'] ?? 'N/A';
        }

        $ficoScore = 'N/A';
        if ($borrower) {
            if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
                $ficoScore = $borrower->creditProfile->fico_score;
            } elseif ($borrower->fico_score !== null) {
                $ficoScore = $borrower->fico_score;
            }
        }

        $monthlyRevenue = 'N/A';
        if ($borrower && $borrower->monthly_revenue !== null) {
            $monthlyRevenue = currencyFormat($borrower->monthly_revenue, 0);
        }

        $timeInBusiness = 'N/A';
        if ($borrower && !empty($borrower->business_start_date)) {
            $timeInBusiness = calculateTimeInBusiness($borrower->business_start_date);
        }

        return [
            'funding_readiness_score' => $fundingReadinessScore,
            'fico_score'              => $ficoScore,
            'monthly_revenue'         => $monthlyRevenue,
            'time_in_business'        => $timeInBusiness,
        ];
    }

    /**
     * Parse a text amount_range (e.g. "$5K - $250K", "$1.5M - $10M") into [min, max] floats.
     * Supports K (thousands), M (millions), B (billions) suffixes, case-insensitive.
     *
     * @param  string  $range
     * @return float[]  [min, max]
     */
    private function parseAmountRange(string $range): array
    {
        // Try to match formatted amounts: $5K, $1.5M, $250K, $10B etc.
        preg_match_all('/\$\s*([\d,]+(?:\.\d+)?)\s*([KkMmBb]?)/', $range, $matches, PREG_SET_ORDER);

        if (!empty($matches)) {
            $amounts = array_map(function ($m) {
                $num    = (float) str_replace(',', '', $m[1]);
                $suffix = strtoupper($m[2]);

                return match ($suffix) {
                    'K'     => $num * 1_000,
                    'M'     => $num * 1_000_000,
                    'B'     => $num * 1_000_000_000,
                    default => $num,
                };
            }, $matches);

            return [(float) min($amounts), (float) max($amounts)];
        }

        // Fallback: match raw number formats like "10000-250000" or "5000 - 500000"
        preg_match_all('/\b(\d+(?:[.,]\d+)?)\b/', $range, $rawMatches);

        if (!empty($rawMatches[1])) {
            $amounts = array_map(fn($n) => (float) str_replace(',', '', $n), $rawMatches[1]);
            return [(float) min($amounts), (float) max($amounts)];
        }

        return [0.0, 0.0];
    }

    /**
     * Get a single funding sequencing detail.
     *
     * @param int|string $id
     * @return \App\Models\Funding\FundingSequencing
     */
    public function getSequencingById($id)
    {
        return FundingSequencing::with(['group', 'type'])
            ->where('status', FundingSequencing::STATUS_ACTIVE)
            ->findOrFail($id);
    }
}
