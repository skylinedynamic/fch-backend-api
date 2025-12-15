<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use App\Models\Limit\CreditLine;

class OverviewService
{
    /**
     * Get credit builder overview data.
     *
     * @return array
     */
    public function myProgress(): array
    {
        $borrower = Borrower::with('tier')->where('id',auth()->user()->entity_id)->first();
        $currentTier = $borrower->tier; 
        $tiers = Tier::orderBy('order', 'asc')->get(['id', 'name', 'title']);

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
        ];
    }
}
