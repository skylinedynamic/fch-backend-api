<?php

namespace Database\Seeders;

use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CreditBuilderVendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        CreditBuilderVendor::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Get Tier IDs (assuming they exist, otherwise fallback to 1-4)
        $tier0 = 1;
        $tier1 = 2;
        $tier2 = 3;
        $tier3 = 4;
        $tier4 = 5;

        $vendors = [
            // Tier 0
            [
                'name' => 'Staples Business Credit',
                'category' => 'Office Supplies',
                'requirements' => [
                    'Business Bank Account',
                    'Business Address',
                    'EIN'
                ],
                'benefits' => [
                    'Online Account Management',
                    'Rewards Program',
                    'Net 30 Terms'
                ],
                'report_to' => ['D&B'],
                'tier_id' => $tier0,
                'status' => 'active',
            ],
            [
                'name' => 'Office Depot Business Credit',
                'category' => 'Office Supplies',
                'requirements' => [
                    'Business Bank Account',
                    'Business Address',
                    'EIN'
                ],
                'benefits' => [
                    'Rewards Program',
                    'Order History',
                    'Net 30 Terms'
                ],
                'report_to' => ['D&B'],
                'tier_id' => $tier0,
                'status' => 'active',
            ],

            // Tier 1
            [
                'name' => 'Uline Business Credit',
                'category' => 'Industrial Supplies',
                'requirements' => [
                    '6 Months in Business',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Volume Discounts',
                    'Free Shipping',
                    'Net 30 Terms'
                ],
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => 'active',
            ],
            [
                'name' => 'Quill Business Credit',
                'category' => 'Office Supplies',
                'requirements' => [
                    '6 Months in Business',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Volume Discounts',
                    'Net 30 Terms',
                    'Order Tracking'
                ],
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => 'active',
            ],
            [
                'name' => 'Grainger Commercial Account',
                'category' => 'Industrial Supplies',
                'requirements' => [
                    'Business Bank Account',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Bulk Pricing',
                    'Quick Delivery',
                    'Net 30 Terms'
                ],
                'report_to' => ['D&B'],
                'tier_id' => $tier1,
                'status' => 'active',
            ],
            [
                'name' => 'MSC Industrial Supply',
                'category' => 'Industrial Supplies',
                'requirements' => [
                    'Business Bank Account',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Volume Discounts',
                    'Account Management',
                    'Net 30 Terms'
                ],
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => 'active',
            ],

            // Tier 2
            [
                'name' => 'Dell Business Credit',
                'category' => 'Technology',
                'requirements' => [
                    'Established Credit History',
                    'Strong Payment History',
                    'Financial Statements'
                ],
                'benefits' => [
                    'Technology Financing',
                    'Business Support',
                    'Flexible Terms'
                ],
                'report_to' => ['D&B', 'Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => 'active',
            ],
            [
                'name' => 'Amazon Business Net Terms',
                'category' => 'Technology',
                'requirements' => [
                    'Established Credit',
                    'Business Financials',
                    'Trade References'
                ],
                'benefits' => [
                    'Flexible Financing',
                    'Wide Selection',
                    'Payment Terms'
                ],
                'report_to' => ['Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => 'active',
            ],

            // Tier 3
            [
                'name' => 'CDW Business Credit',
                'category' => 'Technology',
                'requirements' => [
                    'Established Credit History',
                    'Strong Payment History',
                    'Financial Statements'
                ],
                'benefits' => [
                    'Tech Advisory',
                    'Flexible Financing',
                    'Business Support'
                ],
                'report_to' => ['Experian', 'Equifax'],
                'tier_id' => $tier3,
                'status' => 'active',
            ],
        ];

        foreach ($vendors as $vendor) {
            CreditBuilderVendor::create($vendor);
        }
    }
}
