<?php

namespace App\Services\FCH\Common\Tier;

use App\Models\CreditBuilder\Tier;
use App\Helpers\ApiResponse;

class TierService
{
    /**
     * List all tiers with id and name.
     */
    public function list()
    {
        return Tier::select('id', 'name')->orderBy('id', 'asc')->get();
    }
}
