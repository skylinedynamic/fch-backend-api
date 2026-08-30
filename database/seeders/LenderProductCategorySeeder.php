<?php

namespace Database\Seeders;

use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Seeder;

class LenderProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Business Line of Credit',
            'Term Loan',
            'Working Capital',
            'Revenue-Based Financing',
            'Merchant Cash Advance',
            'SBA Financing',
            'Equipment Financing',
            'Equipment Leasing',
            'Commercial Vehicle Financing',
            'Startup Funding',
            'Business Credit Building',
            'Business Credit Cards',
            'Accounts Receivable Financing',
            'Invoice Factoring',
            'Asset-Based Lending',
            'Commercial Real Estate Financing',
            'Real Estate Investor Financing',
            'Fix & Flip Financing',
            'Bridge Financing',
            'Construction Financing',
            'DSCR Rental Financing',
            'Acquisition Financing',
            'Franchise Financing',
            'Healthcare Financing',
            'Expansion Capital',
            'Renewal / Additional Capital',
            'Other'
        ];

        foreach ($categories as $index => $name) {
            LenderProductCategory::firstOrCreate(
                ['name' => $name],
                [
                    'status' => LenderProductCategory::STATUS_ACTIVE,
                    'sort_order' => $index + 1
                ]
            );
        }
    }
}
