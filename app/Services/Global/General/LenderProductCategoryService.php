<?php

namespace App\Services\Global\General;

use App\Models\Lender\LenderProductCategory;

/**
 * The lender product categories as the funding types an application offers
 * (Preferred / Additional Funding Types) - see LenderProductCategory::fundingTypeNames().
 */
class LenderProductCategoryService
{
    /** @return array<int, string> */
    public function fundingTypeNames(): array
    {
        return LenderProductCategory::fundingTypeNames();
    }
}
