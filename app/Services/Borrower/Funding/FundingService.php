<?php

namespace App\Services\Borrower\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use Illuminate\Support\Facades\Log;

class FundingService
{
    /**
     * Get grouped funding sequencings for the borrower portal.
     * We only return Active groups and their Active sequences that pass visibility logic.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGroupedSequencings()
    {
        $groups = FundingGroup::with(['sequencings' => function ($query) {
            // Eager-load the type relation so engine_status can check type.status
            $query->with('type')
                  ->where('status', FundingSequencing::STATUS_ACTIVE)
                  ->orderBy('sort_order', 'asc');
        }])->where('status', FundingGroup::STATUS_ACTIVE)
          ->orderBy('sort_order', 'asc')
          ->get();

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
        return FundingType::where('status', FundingType::STATUS_ACTIVE)->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Get dynamic dashboard stats derived from the filtered FundingSequencing catalog.
     *
     * @param \Illuminate\Database\Eloquent\Collection $groups
     * @return array
     */
    public function getDashboardStats($groups): array
    {
        $totalMin = 0.0;
        $totalMax = 0.0;
        $totalOptions = 0;

        foreach ($groups as $group) {
            foreach ($group->sequencings as $seq) {
                $totalOptions++;
                if (!empty($seq->amount_range)) {
                    [$min, $max] = $this->parseAmountRange($seq->amount_range);
                    $totalMin += $min;
                    $totalMax += $max;
                }
            }
        }

        return [
            'total_approved'  => $totalMax,
            'total_funded'    => $totalMin,
            'total_options'   => $totalOptions,
            'total_groups'    => $groups->count(),
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
