<?php

namespace App\Services\Global\General;

use App\Models\Funding\FundingSequencing;
use Illuminate\Database\Eloquent\Collection;

/**
 * The funding sequences offered for a funding type - the Funding Sequence
 * dropdown on a funding application's Funding Request step, filtered by the
 * application's Preferred Funding Type.
 */
class FundingSequenceOptionService
{
    /** @return Collection<int, FundingSequencing> */
    public function forFundingType(string $fundingType): Collection
    {
        return FundingSequencing::offeredFor($fundingType)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
