<?php

namespace Database\Seeders;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use Illuminate\Database\Seeder;

class FundingSequencingSeeder extends Seeder
{
    public function run(): void
    {
        // ── Funding Types ──────────────────────────────────────────────────────
        $typeCreditCards    = FundingType::firstOrCreate(['name' => 'Credit Cards'],       ['sort_order' => 1, 'status' => 'Active']);
        $typeWorkingCapital = FundingType::firstOrCreate(['name' => 'Working Capital'],    ['sort_order' => 2, 'status' => 'Active']);
        $typeEquipment      = FundingType::firstOrCreate(['name' => 'Equipment'],          ['sort_order' => 3, 'status' => 'Active']);
        $typeRealEstate     = FundingType::firstOrCreate(['name' => 'Real Estate'],        ['sort_order' => 4, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'SBA Loans'],          ['sort_order' => 5, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Lines of Credit'],    ['sort_order' => 6, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Term Loans'],         ['sort_order' => 7, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Invoice Financing'],  ['sort_order' => 8, 'status' => 'Active']);

        // ── Funding Groups ─────────────────────────────────────────────────────
        $groupCredit     = FundingGroup::firstOrCreate(['name' => 'Business Credit & Credit Cards'], ['sort_order' => 1, 'status' => 'Active']);
        $groupWorking    = FundingGroup::firstOrCreate(['name' => 'Working Capital Solutions'],       ['sort_order' => 2, 'status' => 'Active']);
        $groupEquipment  = FundingGroup::firstOrCreate(['name' => 'Equipment Financing'],             ['sort_order' => 3, 'status' => 'Active']);
        $groupRealEstate = FundingGroup::firstOrCreate(['name' => 'Real Estate Financing'],           ['sort_order' => 4, 'status' => 'Active']);

        // ── Shared helper for common readiness factors ─────────────────────────
        $defaultReadiness = [
            ['label' => 'Business Credit',            'status' => 'Good'],
            ['label' => 'Revenue',                    'status' => 'Strong'],
            ['label' => 'Time in Business',           'status' => 'Strong'],
            ['label' => 'Banking Activity',           'status' => 'Good'],
            ['label' => 'Minimum Monthly Deposits',   'status' => 'Good'],
            ['label' => 'Minimum Average Daily Balance',    'status' => 'Good'],
        ];
        $defaultDocs = [
            'Last 3 months business bank statements',
            'Voided business check',
            'Government-issued ID',
        ];

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 1 — Business Credit & Credit Cards
        // ══════════════════════════════════════════════════════════════════════

        FundingSequencing::updateOrCreate(
            ['name' => '0% Intro Business Cards'],
            [
                'funding_group_id'             => $groupCredit->id,
                'funding_type_id'              => $typeCreditCards->id,
                'subtitle'                     => 'Low/no personal guarantee, 0% APR intro period',
                'description'                  => 'Access business credit cards with 0% intro APR for 12–18 months. Ideal for startups and growing businesses looking to manage cash flow without interest.',
                'amount_range'                 => '$5K - $250K',
                'terms'                        => '0% APR (12–18 mo)',
                'speed_to_funds'               => '7-14 Days',
                'icon'                         => 'ri-bank-card-line',
                'icon_color'                   => 'green',
                'sort_order'                   => 1,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Inventory', 'Payroll', 'Marketing'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Business Credit Builder Cards'],
            [
                'funding_group_id'             => $groupCredit->id,
                'funding_type_id'              => $typeCreditCards->id,
                'subtitle'                     => 'Build business credit while accessing revolving capital',
                'description'                  => 'Net-30 vendor accounts and secured business credit cards designed to establish and grow your business credit profile.',
                'amount_range'                 => '$2K - $100K',
                'terms'                        => 'Net 30',
                'speed_to_funds'               => '7-14 Days',
                'icon'                         => 'ri-bank-card-line',
                'icon_color'                   => 'blue',
                'sort_order'                   => 2,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Inventory', 'Payroll'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Premium Rewards Cards'],
            [
                'funding_group_id'             => $groupCredit->id,
                'funding_type_id'              => $typeCreditCards->id,
                'subtitle'                     => 'High-limit cards with cash back, travel & premium perks',
                'description'                  => 'Premium business credit cards with high credit limits, bonus rewards programs, and exclusive benefits for established businesses.',
                'amount_range'                 => '$10K - $500K',
                'terms'                        => 'Rewards',
                'speed_to_funds'               => '7-14 Days',
                'icon'                         => 'ri-star-line',
                'icon_color'                   => 'purple',
                'sort_order'                   => 3,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Travel', 'Marketing', 'Operations'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Vendor Credit Lines'],
            [
                'funding_group_id'             => $groupCredit->id,
                'funding_type_id'              => $typeCreditCards->id,
                'subtitle'                     => 'Net 30/60/90 terms with major vendors & suppliers',
                'description'                  => 'Establish trade credit lines with major vendors and suppliers. Purchase inventory and supplies on net terms to preserve cash flow.',
                'amount_range'                 => '$1K - $250K',
                'terms'                        => 'Net 30/60/90',
                'speed_to_funds'               => '3-7 Days',
                'icon'                         => 'ri-shopping-cart-2-line',
                'icon_color'                   => 'orange',
                'sort_order'                   => 4,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Inventory', 'Supplies', 'Equipment', 'Operations'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => $defaultDocs,
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 2 — Working Capital Solutions
        // ══════════════════════════════════════════════════════════════════════

        FundingSequencing::updateOrCreate(
            ['name' => 'Business Lines of Credit'],
            [
                'funding_group_id'             => $groupWorking->id,
                'funding_type_id'              => $typeWorkingCapital->id,
                'subtitle'                     => 'Revolving credit line — draw, repay, repeat',
                'description'                  => 'Flexible revolving business line of credit. Draw funds as needed, only pay interest on what you use. Perfect for managing cash flow and seasonal needs.',
                'amount_range'                 => '$10K - $250K',
                'terms'                        => 'Revolving / 6-24 mo',
                'speed_to_funds'               => '1-5 Days',
                'icon'                         => 'ri-refresh-line',
                'icon_color'                   => 'green',
                'sort_order'                   => 1,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Payroll', 'Inventory', 'Cash Flow'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Voided business check',
                    'Government-issued ID',
                    'Business license (if applicable)',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Term Loans'],
            [
                'funding_group_id'             => $groupWorking->id,
                'funding_type_id'              => $typeWorkingCapital->id,
                'subtitle'                     => 'Fixed monthly payments for business expansion & growth',
                'description'                  => 'Business term loans with fixed monthly payments and competitive rates. Use funds for expansion, hiring, equipment, or any business need.',
                'amount_range'                 => '$25K - $5M',
                'terms'                        => '6-60 Months',
                'speed_to_funds'               => '3-7 Days',
                'icon'                         => 'ri-bank-line',
                'icon_color'                   => 'blue',
                'sort_order'                   => 2,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Expansion', 'Hiring', 'Equipment', 'Inventory', 'Working Capital'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Last 2 years business tax returns',
                    'Voided business check',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Merchant Cash Advance'],
            [
                'funding_group_id'             => $groupWorking->id,
                'funding_type_id'              => $typeWorkingCapital->id,
                'subtitle'                     => 'Fast funding based on daily credit card & bank receipts',
                'description'                  => 'Advance against your future business receipts. Repayment is a fixed percentage of daily sales — easy approval, no fixed monthly payments.',
                'amount_range'                 => '$5K - $500K',
                'terms'                        => '3-18 Months',
                'speed_to_funds'               => '24-72 Hours',
                'icon'                         => 'ri-speed-line',
                'icon_color'                   => 'purple',
                'sort_order'                   => 3,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Payroll', 'Marketing', 'Emergency Costs'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Voided business check',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Revenue Based Financing'],
            [
                'funding_group_id'             => $groupWorking->id,
                'funding_type_id'              => $typeWorkingCapital->id,
                'subtitle'                     => 'Flexible funding — repay as a % of monthly revenue',
                'description'                  => 'Revenue-based financing with flexible repayment tied to your monthly sales. Payments scale with your revenue — lower when sales are slow, higher when sales are strong.',
                'amount_range'                 => '$10K - $3M',
                'terms'                        => 'Flexible / % of Revenue',
                'speed_to_funds'               => '3-7 Days',
                'icon'                         => 'ri-line-chart-line',
                'icon_color'                   => 'orange',
                'sort_order'                   => 4,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Working Capital', 'Growth', 'Payroll', 'Inventory'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 6 months business bank statements',
                    'Last 6 months merchant processing statements (if applicable)',
                    'Voided business check',
                ],
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 3 — Equipment Financing
        // ══════════════════════════════════════════════════════════════════════

        FundingSequencing::updateOrCreate(
            ['name' => 'Equipment Loans'],
            [
                'funding_group_id'             => $groupEquipment->id,
                'funding_type_id'              => $typeEquipment->id,
                'subtitle'                     => 'Finance new or used equipment with competitive rates',
                'description'                  => 'Business equipment loans with fixed monthly payments. The equipment itself serves as collateral — easier approval than unsecured loans.',
                'amount_range'                 => '$10K - $2M',
                'terms'                        => 'Up to 84 Months',
                'speed_to_funds'               => '3-10 Days',
                'icon'                         => 'ri-settings-3-line',
                'icon_color'                   => 'green',
                'sort_order'                   => 1,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Equipment Purchase', 'Machinery', 'Technology', 'Vehicles'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Equipment invoice or quote',
                    'Government-issued ID',
                    'Voided business check',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Equipment Leasing'],
            [
                'funding_group_id'             => $groupEquipment->id,
                'funding_type_id'              => $typeEquipment->id,
                'subtitle'                     => 'Preserve cash flow — use equipment without buying it',
                'description'                  => 'Lease equipment with low monthly payments. Conserve capital, stay current with technology, and enjoy potential tax advantages.',
                'amount_range'                 => '$5K - $1M',
                'terms'                        => '$1 Buyout Option',
                'speed_to_funds'               => '3-10 Days',
                'icon'                         => 'ri-file-text-line',
                'icon_color'                   => 'blue',
                'sort_order'                   => 2,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Equipment', 'Technology', 'Medical Devices', 'Vehicles'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Equipment invoice or quote',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Working Capital Equipment'],
            [
                'funding_group_id'             => $groupEquipment->id,
                'funding_type_id'              => $typeEquipment->id,
                'subtitle'                     => 'Equipment financing bundled with working capital',
                'description'                  => 'Finance equipment AND receive additional working capital in a single facility. Ideal for businesses that need to upgrade equipment while maintaining cash flow.',
                'amount_range'                 => '$25K - $500K',
                'terms'                        => '12-60 Months',
                'speed_to_funds'               => '3-7 Days',
                'icon'                         => 'ri-briefcase-line',
                'icon_color'                   => 'purple',
                'sort_order'                   => 3,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Equipment', 'Working Capital', 'Inventory', 'Operations'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Transportation Financing'],
            [
                'funding_group_id'             => $groupEquipment->id,
                'funding_type_id'              => $typeEquipment->id,
                'subtitle'                     => 'Trucks, trailers & fleet financing solutions',
                'description'                  => 'Finance commercial vehicles, trucks, trailers, and transportation fleets. Expand your logistics capacity with competitive rates and long terms.',
                'amount_range'                 => '$25K - $1M',
                'terms'                        => 'Up to 72 Months',
                'speed_to_funds'               => '3-10 Days',
                'icon'                         => 'ri-truck-line',
                'icon_color'                   => 'orange',
                'sort_order'                   => 4,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Trucks', 'Trailers', 'Fleet', 'Transportation'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 3 months business bank statements',
                    'Vehicle invoice or listing',
                    'Government-issued ID',
                    'CDL (if applicable)',
                ],
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 4 — Real Estate Financing
        // ══════════════════════════════════════════════════════════════════════

        FundingSequencing::updateOrCreate(
            ['name' => 'Commercial Real Estate'],
            [
                'funding_group_id'             => $groupRealEstate->id,
                'funding_type_id'              => $typeRealEstate->id,
                'subtitle'                     => 'Purchase, refinance, or renovate commercial properties',
                'description'                  => 'Long-term commercial real estate loans for purchasing or refinancing commercial properties. Competitive rates with up to 25-year amortization.',
                'amount_range'                 => '$100K - $10M',
                'terms'                        => '10-25 Years',
                'speed_to_funds'               => '30-60 Days',
                'icon'                         => 'ri-building-4-line',
                'icon_color'                   => 'green',
                'sort_order'                   => 1,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Property Purchase', 'Refinancing', 'Renovation', 'Investment'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Last 2 years business and personal tax returns',
                    'Property appraisal',
                    'Last 3 months business bank statements',
                    'Purchase agreement or lease agreement',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Bridge Loans'],
            [
                'funding_group_id'             => $groupRealEstate->id,
                'funding_type_id'              => $typeRealEstate->id,
                'subtitle'                     => 'Short-term financing for real estate opportunities',
                'description'                  => 'Bridge loans provide short-term financing when you need to close quickly on a property before securing long-term financing.',
                'amount_range'                 => '$50K - $5M',
                'terms'                        => '6-24 Months',
                'speed_to_funds'               => '7-21 Days',
                'icon'                         => 'ri-links-line',
                'icon_color'                   => 'blue',
                'sort_order'                   => 2,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Property Acquisition', 'Bridge Financing', 'Quick Close'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Property appraisal or estimated value',
                    'Purchase agreement',
                    'Exit strategy documentation',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Fix & Flip Loans'],
            [
                'funding_group_id'             => $groupRealEstate->id,
                'funding_type_id'              => $typeRealEstate->id,
                'subtitle'                     => 'Finance your real estate investment & renovation projects',
                'description'                  => 'Short-term loans designed for real estate investors to purchase, renovate, and sell residential or commercial properties.',
                'amount_range'                 => '$50K - $3M',
                'terms'                        => '6-18 Months',
                'speed_to_funds'               => '7-14 Days',
                'icon'                         => 'ri-tools-line',
                'icon_color'                   => 'purple',
                'sort_order'                   => 3,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['Property Purchase', 'Renovation', 'Fix & Flip', 'Investment'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Property appraisal or ARV estimate',
                    'Renovation scope and budget',
                    'Purchase agreement',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Construction Loans'],
            [
                'funding_group_id'             => $groupRealEstate->id,
                'funding_type_id'              => $typeRealEstate->id,
                'subtitle'                     => 'Ground-up construction financing for new development',
                'description'                  => 'Construction loans for ground-up development projects. Funds are disbursed in draws as construction milestones are completed.',
                'amount_range'                 => '$100K - $10M',
                'terms'                        => '12-24 Months',
                'speed_to_funds'               => '21-45 Days',
                'icon'                         => 'ri-user-settings-line',
                'icon_color'                   => 'orange',
                'sort_order'                   => 4,
                'status'                       => 'Active',
                'action_url'                   => null,
                'uses'                         => ['New Construction', 'Ground-Up Development', 'Land Development'],

                'readiness_factors'            => $defaultReadiness,
                'missing_documents'            => [
                    'Approved construction plans',
                    'Contractor agreement and budget',
                    'Land deed or purchase agreement',
                    'Last 2 years personal tax returns',
                    'Government-issued ID',
                ],
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 5 — Seed lender_funding_sequencings Pivot Data
        // ══════════════════════════════════════════════════════════════════════

        $lenderIds = Lender::pluck('id')->toArray();
        $lender1 = $lenderIds[0] ?? 1;
        $lender2 = $lenderIds[1] ?? 2;
        $lender3 = $lenderIds[2] ?? 3;

        $lenderFundingSequencings = [
            ['lender_id' => $lender2, 'funding_sequencing_id' => 17, 'min_fico' => 666, 'min_tib_months' => 10, 'min_monthly_revenue' => 10, 'min_monthly_deposits' => 10, 'min_average_daily_balance' => 10, 'max_nsf_count' => 10, 'max_negative_days' => 10, 'min_dscr' => null, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 0, 'require_documents_uploaded' => 0, 'allowed_documents' => '[]', 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 03:29:11', 'updated_at' => '2026-06-11 03:29:11'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 19, 'min_fico' => 37, 'min_tib_months' => 12, 'min_monthly_revenue' => 10, 'min_monthly_deposits' => 10, 'min_average_daily_balance' => 38.00, 'max_nsf_count' => 20, 'max_negative_days' => 9, 'min_dscr' => 23.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 1, 'require_documents_uploaded' => 0, 'allowed_documents' => '[]', 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 03:29:31', 'updated_at' => '2026-06-11 03:29:31'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 14, 'min_fico' => 16, 'min_tib_months' => 11, 'min_monthly_revenue' => 10, 'min_monthly_deposits' => 5.00, 'min_average_daily_balance' => 95.00, 'max_nsf_count' => 68, 'max_negative_days' => 21, 'min_dscr' => 8.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 1, 'require_documents_uploaded' => 1, 'allowed_documents' => '[1, 2, 3]', 'manual_review_allowed' => 0, 'exception_allowed' => 1, 'created_at' => '2026-06-11 03:47:22', 'updated_at' => '2026-06-11 03:47:22'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 20, 'min_fico' => 666, 'min_tib_months' => 10, 'min_monthly_revenue' => 10, 'min_monthly_deposits' => 10, 'min_average_daily_balance' => 10, 'max_nsf_count' => 10, 'max_negative_days' => 10, 'min_dscr' => null, 'allowed_states' => null, 'restricted_states' => null, 'allowed_industries' => null, 'restricted_industries' => null, 'require_completed_application' => 0, 'require_documents_uploaded' => 0, 'allowed_documents' => null, 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 03:57:29', 'updated_at' => '2026-06-11 03:57:29'],
            ['lender_id' => $lender3, 'funding_sequencing_id' => 21, 'min_fico' => 666, 'min_tib_months' => 10, 'min_monthly_revenue' => 10, 'min_monthly_deposits' => 10, 'min_average_daily_balance' => 10, 'max_nsf_count' => 10, 'max_negative_days' => 10, 'min_dscr' => null, 'allowed_states' => null, 'restricted_states' => null, 'allowed_industries' => null, 'restricted_industries' => null, 'require_completed_application' => 0, 'require_documents_uploaded' => 0, 'allowed_documents' => null, 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 03:58:12', 'updated_at' => '2026-06-11 03:58:12'],
            ['lender_id' => $lender1, 'funding_sequencing_id' => 22, 'min_fico' => 666, 'min_tib_months' => 10, 'min_monthly_revenue' => 3.00, 'min_monthly_deposits' => 12.00, 'min_average_daily_balance' => 82.00, 'max_nsf_count' => 93, 'max_negative_days' => 11, 'min_dscr' => 33.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 1, 'require_documents_uploaded' => 0, 'allowed_documents' => '[]', 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 04:08:39', 'updated_at' => '2026-06-11 04:08:39'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 23, 'min_fico' => 30, 'min_tib_months' => 8, 'min_monthly_revenue' => 2.00, 'min_monthly_deposits' => 5.00, 'min_average_daily_balance' => 55.00, 'max_nsf_count' => 99, 'max_negative_days' => 5, 'min_dscr' => 72.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 0, 'require_documents_uploaded' => 1, 'allowed_documents' => '[1, 2]', 'manual_review_allowed' => 1, 'exception_allowed' => 1, 'created_at' => '2026-06-11 04:19:15', 'updated_at' => '2026-06-11 04:19:15'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 24, 'min_fico' => 888, 'min_tib_months' => 1, 'min_monthly_revenue' => 1.00, 'min_monthly_deposits' => 11.00, 'min_average_daily_balance' => 14.00, 'max_nsf_count' => 25, 'max_negative_days' => 4, 'min_dscr' => 52.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 1, 'require_documents_uploaded' => 1, 'allowed_documents' => '[1, 2]', 'manual_review_allowed' => 0, 'exception_allowed' => 0, 'created_at' => '2026-06-11 04:21:03', 'updated_at' => '2026-06-11 04:21:03'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 25, 'min_fico' => 29, 'min_tib_months' => 8, 'min_monthly_revenue' => 5.00, 'min_monthly_deposits' => 1.00, 'min_average_daily_balance' => 49.00, 'max_nsf_count' => 18, 'max_negative_days' => 24, 'min_dscr' => 92.00, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 0, 'require_documents_uploaded' => 1, 'allowed_documents' => '[1, 2, 3]', 'manual_review_allowed' => 1, 'exception_allowed' => 0, 'created_at' => '2026-06-11 04:24:28', 'updated_at' => '2026-06-11 04:24:28'],
            ['lender_id' => $lender2, 'funding_sequencing_id' => 26, 'min_fico' => 10, 'min_tib_months' => 10, 'min_monthly_revenue' => 87887.00, 'min_monthly_deposits' => 87886.00, 'min_average_daily_balance' => 87878.00, 'max_nsf_count' => 10, 'max_negative_days' => null, 'min_dscr' => null, 'allowed_states' => '[]', 'restricted_states' => '[]', 'allowed_industries' => '[]', 'restricted_industries' => '[]', 'require_completed_application' => 1, 'require_documents_uploaded' => 1, 'allowed_documents' => '[4, 3, 2]', 'manual_review_allowed' => 1, 'exception_allowed' => 1, 'created_at' => '2026-06-11 04:33:51', 'updated_at' => '2026-06-11 04:33:51']
        ];

        $sequencingIds = FundingSequencing::orderBy('id')->pluck('id')->toArray();
        
        foreach ($lenderFundingSequencings as $index => $lfs) {
            // Map the hardcoded IDs to the actual IDs generated in this run
            $actualSeqId = $sequencingIds[$index] ?? null;
            if (!$actualSeqId) continue;

            $visibilityData = [
                'min_fico' => $lfs['min_fico'],
                'min_tib_months' => $lfs['min_tib_months'],
                'min_monthly_revenue' => $lfs['min_monthly_revenue'],
                'min_monthly_deposits' => $lfs['min_monthly_deposits'],
                'min_average_daily_balance' => $lfs['min_average_daily_balance'],
                'max_nsf_count' => $lfs['max_nsf_count'],
                'max_negative_days' => $lfs['max_negative_days'],
                'min_dscr' => $lfs['min_dscr'],
                'allowed_states' => json_decode($lfs['allowed_states'] ?? '[]', true) ?: [],
                'restricted_states' => json_decode($lfs['restricted_states'] ?? '[]', true) ?: [],
                'allowed_industries' => json_decode($lfs['allowed_industries'] ?? '[]', true) ?: [],
                'restricted_industries' => json_decode($lfs['restricted_industries'] ?? '[]', true) ?: [],
            ];

            LenderVisibility::updateOrCreate(
                [
                    'lender_id' => $lfs['lender_id'],
                    'funding_sequencing_id' => $actualSeqId
                ],
                $visibilityData
            );

            FundingSequencing::where('id', $actualSeqId)->update([
                'require_completed_application' => $lfs['require_completed_application'],
                'require_documents_uploaded' => $lfs['require_documents_uploaded'],
                'allowed_documents' => json_decode($lfs['allowed_documents'] ?? '[]', true) ?: [],
                'manual_review_allowed' => $lfs['manual_review_allowed'],
                'exception_allowed' => $lfs['exception_allowed'],
            ]);
        }

        $this->command->info('✅ FundingSequencingSeeder completed — 16 sequencings across 4 groups seeded with accurate, open visibility rules. Also populated Lenders and lender_visibilities.');
    }
}
