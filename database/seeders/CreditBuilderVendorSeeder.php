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
            // Tier 1
            [
                'name' => 'Uline Business Credit',
                'category' => 'Shipping Supplies',
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
                'affiliate_url' => '',
                'general_url' => 'https://www.uline.com',
                'logo' => 'logos/tier-1/uline.png',
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
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
                'affiliate_url' => '',
                'general_url' => 'https://www.quill.com/quill-net-30/cbi/94.cshtml',
                'logo' => 'logos/tier-1/quill.png',
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Grainger Commercial Account',
                'category' => 'Industrial / MRO',
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
                'affiliate_url' => '',
                'general_url' => 'https://www.grainger.com/content/user_registration',
                'logo' => 'logos/tier-1/grainger_logo.png',
                'report_to' => ['D&B'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'MSC Industrial Supply',
                'category' => 'Industrial / MRO',
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
                'affiliate_url' => '',
                'general_url' => 'https://www.mscdirect.com/customer-service/credit',
                'logo' => 'logos/tier-1/msc.jpg',
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Crown Office Supplies',
                'category' => 'Office Supplies',
                'requirements' => [
                    '6 Months in Business',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Volume Discounts',
                    'Net 30 Terms',
                    'Account Management'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://crownofficesupplies.com/net30-application/',
                'logo' => 'logos/tier-1/crown.png',
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Global Industrial',
                'category' => 'Warehouse, Safety & Shipping',
                'requirements' => [
                    'Business Bank Account',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Warehouse Solutions',
                    'Safety Products',
                    'Net 30 Terms'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.globalindustrial.com/applyforcredit',
                'logo' => 'logos/tier-1/global_logo.png',
                'report_to' => ['D&B'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Seton',
                'category' => 'Safety & Compliance Supplies',
                'requirements' => [
                    '6 Months in Business',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Safety Compliance',
                    'Volume Pricing',
                    'Net 30 Terms'
                ],
                'affiliate_url' => 'https://www.staples.com/business-credit',
                'general_url' => 'https://www.seton.com',
                'logo' => 'logos/tier-1/seton_logo.png',
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Office Garner',
                'category' => 'Office Supplies',
                'requirements' => [
                    '6 Months in Business',
                    'Trade References',
                    'D&B Profile'
                ],
                'benefits' => [
                    'Office Solutions',
                    'Volume Discounts',
                    'Net 30 Terms'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://officegarner.com/checkout/?add-to-cart=8687',
                'logo' => 'logos/tier-1/office_garner_logo.png',
                'report_to' => ['D&B'],
                'tier_id' => $tier1,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],

            // Tier 2
            [
                'name' => 'Office Depot',
                'category' => 'Business Credit Card',
                'requirements' => [
                    'Established Credit History',
                    'Strong Payment History',
                    'Financial Statements'
                ],
                'benefits' => [
                    'Rewards Program',
                    'Business Support',
                    'Flexible Terms'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.officedepot.com',
                'logo' => 'logos/tier-2/office-depot.png',
                'report_to' => ['D&B', 'Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Staples',
                'category' => 'Business Credit Card',
                'requirements' => [
                    'Established Credit',
                    'Business Financials',
                    'Trade References'
                ],
                'benefits' => [
                    'Rewards Program',
                    'Office Solutions',
                    'Payment Terms'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.staples.com/business-credit',
                'logo' => 'logos/tier-2/staples.png',
                'report_to' => ['Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Amazon Business',
                'category' => 'Pay by Invoice / Net Terms',
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
                'affiliate_url' => '',
                'general_url' => 'https://www.amazon.com/b2b',
                'logo' => 'logos/tier-2/amazon-business.png',
                'report_to' => ['Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Fuel Cards (Shell / BP / WEX)',
                'category' => 'Small Fleet Fuel Cards',
                'requirements' => [
                    'Established Business',
                    'Credit Check',
                    'Business Documentation'
                ],
                'benefits' => [
                    'Fuel Discounts',
                    'Fleet Management',
                    'Reporting Tools'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.wexinc.com',
                'logo' => 'logos/tier-2/Oil-companies.jpg',
                'report_to' => ['D&B', 'Experian'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Lowe\'s',
                'category' => 'Commercial Credit Account',
                'requirements' => [
                    'Established Credit',
                    'Business Information',
                    'Credit Application'
                ],
                'benefits' => [
                    'Volume Discounts',
                    'Project Support',
                    'Flexible Terms'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.lowes.com/l/pro',
                'logo' => 'logos/tier-2/lowe.png',
                'report_to' => ['D&B', 'Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
            [
                'name' => 'Home Depot',
                'category' => 'Commercial Credit Account',
                'requirements' => [
                    'Established Credit',
                    'Business Documentation',
                    'Credit Check'
                ],
                'benefits' => [
                    'Volume Pricing',
                    'Project Support',
                    'Flexible Financing'
                ],
                'affiliate_url' => '',
                'general_url' => 'https://www.homedepot.com/c/pro',
                'logo' => 'logos/tier-2/home-depot.png',
                'report_to' => ['D&B', 'Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
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
                'affiliate_url' => 'https://www.staples.com/business-credit',
                'general_url' => 'https://www.cdw.com',
                'logo' => asset('Logos/cdw.png'),
                'report_to' => ['Experian', 'Equifax'],
                'tier_id' => $tier3,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ],
        ];

        foreach ($vendors as $vendor) {
            CreditBuilderVendor::create($vendor);
        }
    }
}
