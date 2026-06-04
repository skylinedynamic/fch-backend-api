<?php

namespace App\Services\Borrower\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;

class FundingService
{
    /**
     * Get grouped funding sequencings for the borrower portal.
     * We only return Active groups and their Active sequences.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGroupedSequencings()
    {
        return FundingGroup::with(['sequencings' => function ($query) {
            $query->where('status', FundingSequencing::STATUS_ACTIVE)->orderBy('sort_order', 'asc');
        }])->where('status', FundingGroup::STATUS_ACTIVE)->orderBy('sort_order', 'asc')->get();
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
     * Get dynamic dashboard stats derived from the FundingSequencing catalog.
     *
     * Stats returned:
     *  - total_approved       : sum of the MAX amounts parsed from active sequencings' amount_range
     *  - total_funded         : sum of the MIN amounts parsed from active sequencings' amount_range
     *  - total_options        : count of all active funding sequencings (products available)
     *  - total_groups         : count of all active funding groups
     *
     * @return array
     */
    public function getDashboardStats(): array
    {
        // Fetch all active sequencings that have an amount_range value
        $sequencings = FundingSequencing::where('status', FundingSequencing::STATUS_ACTIVE)
            ->whereNotNull('amount_range')
            ->where('amount_range', '!=', '')
            ->get(['amount_range']);

        $totalMin = 0.0;
        $totalMax = 0.0;

        foreach ($sequencings as $seq) {
            [$min, $max] = $this->parseAmountRange($seq->amount_range);
            $totalMin += $min;
            $totalMax += $max;
        }

        // Count of ALL active sequencings (including those without an amount_range)
        $totalOptions = FundingSequencing::where('status', FundingSequencing::STATUS_ACTIVE)->count();

        // Count of active groups
        $totalGroups = FundingGroup::where('status', FundingGroup::STATUS_ACTIVE)->count();

        return [
            'total_approved'  => $totalMax,    // sum of max amounts across all active options
            'total_funded'    => $totalMin,    // sum of min amounts across all active options
            'total_options'   => $totalOptions, // how many funding products exist
            'total_groups'    => $totalGroups,  // how many groups exist
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
        // Find all dollar amounts, e.g. $5K, $1.5M, $250,000
        preg_match_all('/\$\s*([\d,]+(?:\.\d+)?)\s*([KkMmBb]?)/', $range, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [0.0, 0.0];
        }

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
