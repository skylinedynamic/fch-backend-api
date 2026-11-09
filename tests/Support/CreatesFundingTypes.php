<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Preferred / Additional Funding Types are the Active lender product
 * categories (Lenders > Product Categories), so a test that saves a funding
 * request step needs the categories it sends to exist.
 */
trait CreatesFundingTypes
{
    /** @param  array<int, string>  $names */
    protected function createFundingTypes(array $names = ['Term Loan', 'Business Line of Credit', 'SBA Financing', 'Equipment Financing']): void
    {
        foreach (array_values($names) as $i => $name) {
            DB::table('lender_product_categories')->insert([
                'name' => $name,
                'status' => 'Active',
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
