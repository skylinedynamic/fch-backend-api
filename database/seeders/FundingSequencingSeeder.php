<?php

namespace Database\Seeders;

use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Seeder;

class FundingSequencingSeeder extends Seeder
{
    public function run(): void
    {
        // ── Funding Types ──────────────────────────────────────────────────────
        $typeCreditCards = FundingType::firstOrCreate(['name' => 'Credit Cards'], ['sort_order' => 1, 'status' => 'Active']);
        $typeWorkingCapital = FundingType::firstOrCreate(['name' => 'Working Capital'], ['sort_order' => 2, 'status' => 'Active']);
        $typeEquipment = FundingType::firstOrCreate(['name' => 'Equipment'], ['sort_order' => 3, 'status' => 'Active']);
        $typeRealEstate = FundingType::firstOrCreate(['name' => 'Real Estate'], ['sort_order' => 4, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'SBA Loans'], ['sort_order' => 5, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Lines of Credit'], ['sort_order' => 6, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Term Loans'], ['sort_order' => 7, 'status' => 'Active']);
        FundingType::firstOrCreate(['name' => 'Invoice Financing'], ['sort_order' => 8, 'status' => 'Active']);

        // ── Funding Groups ─────────────────────────────────────────────────────
        $groupCredit = FundingGroup::firstOrCreate(['name' => 'Business Credit & Credit Cards'], ['sort_order' => 1, 'status' => 'Active']);
        $groupWorking = FundingGroup::firstOrCreate(['name' => 'Working Capital Solutions'], ['sort_order' => 2, 'status' => 'Active']);
        $groupEquipment = FundingGroup::firstOrCreate(['name' => 'Equipment Financing'], ['sort_order' => 3, 'status' => 'Active']);
        $groupRealEstate = FundingGroup::firstOrCreate(['name' => 'Real Estate Financing'], ['sort_order' => 4, 'status' => 'Active']);

        // ── Product Categories (Used as Funding Types) ─────────────────────────
        $catCreditCards = LenderProductCategory::firstOrCreate(['name' => 'Business Credit Cards']);
        $catCreditBuilder = LenderProductCategory::firstOrCreate(['name' => 'Business Credit Building']);
        $catStartup = LenderProductCategory::firstOrCreate(['name' => 'Startup Funding']);
        
        $catLOC = LenderProductCategory::firstOrCreate(['name' => 'Business Line of Credit']);
        $catTerm = LenderProductCategory::firstOrCreate(['name' => 'Term Loan']);
        $catMCA = LenderProductCategory::firstOrCreate(['name' => 'Merchant Cash Advance']);
        $catRBF = LenderProductCategory::firstOrCreate(['name' => 'Revenue-Based Financing']);
        
        $catEquip = LenderProductCategory::firstOrCreate(['name' => 'Equipment Financing']);
        $catLease = LenderProductCategory::firstOrCreate(['name' => 'Equipment Leasing']);
        $catTransport = LenderProductCategory::firstOrCreate(['name' => 'Commercial Vehicle Financing']);
        
        $catCRE = LenderProductCategory::firstOrCreate(['name' => 'Commercial Real Estate Financing']);
        $catBridge = LenderProductCategory::firstOrCreate(['name' => 'Bridge Financing']);
        $catFixFlip = LenderProductCategory::firstOrCreate(['name' => 'Fix & Flip Financing']);
        $catConst = LenderProductCategory::firstOrCreate(['name' => 'Construction Financing']);

        // ── Shared helper for common readiness factors ─────────────────────────
        $defaultReadiness = [
            ['label' => 'Business Credit', 'status' => 'Good'],
            ['label' => 'Revenue', 'status' => 'Strong'],
            ['label' => 'Time in Business', 'status' => 'Strong'],
            ['label' => 'Banking Activity', 'status' => 'Good'],
            ['label' => 'Minimum Monthly Deposits', 'status' => 'Good'],
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
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $catTerm->id,
                'subtitle' => 'Low/no personal guarantee, 0% APR intro period',
                'description' => 'Access business credit cards with 0% intro APR for 12–18 months. Ideal for startups and growing businesses looking to manage cash flow without interest.',
                'amount_range' => '5000-250000',
                'terms' => '0% APR (12–18 mo)',
                'speed_to_funds' => '7-14 Days',
                'icon' => 'ri-bank-card-line',
                'icon_color' => 'green',
                'sort_order' => 1,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Inventory', 'Payroll', 'Marketing'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Business Credit Builder Cards'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $catCreditBuilder->id,
                'subtitle' => 'Build business credit while accessing revolving capital',
                'description' => 'Net-30 vendor accounts and secured business credit cards designed to establish and grow your business credit profile.',
                'amount_range' => '2000-100000',
                'terms' => 'Net 30',
                'speed_to_funds' => '7-14 Days',
                'icon' => 'ri-bank-card-line',
                'icon_color' => 'blue',
                'sort_order' => 2,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Premium Rewards Cards'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $catCreditCards->id,
                'subtitle' => 'High-limit cards with cash back, travel & premium perks',
                'description' => 'Premium business credit cards with high credit limits, bonus rewards programs, and exclusive benefits for established businesses.',
                'amount_range' => '10000-500000',
                'terms' => 'Rewards',
                'speed_to_funds' => '7-14 Days',
                'icon' => 'ri-star-line',
                'icon_color' => 'purple',
                'sort_order' => 3,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Travel', 'Marketing', 'Operations'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Vendor Credit Lines'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $catCreditBuilder->id,
                'subtitle' => 'Net 30/60/90 terms with major vendors & suppliers',
                'description' => 'Establish trade credit lines with major vendors and suppliers. Purchase inventory and supplies on net terms to preserve cash flow.',
                'amount_range' => '1000-250000',
                'terms' => 'Net 30/60/90',
                'speed_to_funds' => '3-7 Days',
                'icon' => 'ri-shopping-cart-2-line',
                'icon_color' => 'orange',
                'sort_order' => 4,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Inventory', 'Supplies', 'Equipment', 'Operations'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => $defaultDocs,
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 2 — Working Capital Solutions
        // ══════════════════════════════════════════════════════════════════════

        FundingSequencing::updateOrCreate(
            ['name' => 'Business Lines of Credit'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $catLOC->id,
                'subtitle' => 'Revolving credit line — draw, repay, repeat',
                'description' => 'Flexible revolving business line of credit. Draw funds as needed, only pay interest on what you use. Perfect for managing cash flow and seasonal needs.',
                'amount_range' => '10000-250000',
                'terms' => 'Revolving / 6-24 mo',
                'speed_to_funds' => '1-5 Days',
                'icon' => 'ri-refresh-line',
                'icon_color' => 'green',
                'sort_order' => 1,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Payroll', 'Inventory', 'Cash Flow'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $catTerm->id,
                'subtitle' => 'Fixed monthly payments for business expansion & growth',
                'description' => 'Business term loans with fixed monthly payments and competitive rates. Use funds for expansion, hiring, equipment, or any business need.',
                'amount_range' => '25000-5000000',
                'terms' => '6-60 Months',
                'speed_to_funds' => '3-7 Days',
                'icon' => 'ri-bank-line',
                'icon_color' => 'blue',
                'sort_order' => 2,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Expansion', 'Hiring', 'Equipment', 'Inventory', 'Working Capital'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $catMCA->id,
                'subtitle' => 'Fast funding based on daily credit card & bank receipts',
                'description' => 'Advance against your future business receipts. Repayment is a fixed percentage of daily sales — easy approval, no fixed monthly payments.',
                'amount_range' => '5000-500000',
                'terms' => '3-18 Months',
                'speed_to_funds' => '24-72 Hours',
                'icon' => 'ri-speed-line',
                'icon_color' => 'purple',
                'sort_order' => 3,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Payroll', 'Marketing', 'Emergency Costs'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Revenue Based Financing'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $catRBF->id,
                'subtitle' => 'Flexible funding — repay as a % of monthly revenue',
                'description' => 'Revenue-based financing with flexible repayment tied to your monthly sales. Payments scale with your revenue — lower when sales are slow, higher when sales are strong.',
                'amount_range' => '10000-3000000',
                'terms' => 'Flexible / % of Revenue',
                'speed_to_funds' => '3-7 Days',
                'icon' => 'ri-line-chart-line',
                'icon_color' => 'orange',
                'sort_order' => 4,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Working Capital', 'Growth', 'Payroll', 'Inventory'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $catEquip->id,
                'subtitle' => 'Finance new or used equipment with competitive rates',
                'description' => 'Business equipment loans with fixed monthly payments. The equipment itself serves as collateral — easier approval than unsecured loans.',
                'amount_range' => '10000-2000000',
                'terms' => 'Up to 84 Months',
                'speed_to_funds' => '3-10 Days',
                'icon' => 'ri-settings-3-line',
                'icon_color' => 'green',
                'sort_order' => 1,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Equipment Purchase', 'Machinery', 'Technology', 'Vehicles'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $catLease->id,
                'subtitle' => 'Preserve cash flow — use equipment without buying it',
                'description' => 'Lease equipment with low monthly payments. Conserve capital, stay current with technology, and enjoy potential tax advantages.',
                'amount_range' => '5000-1000000',
                'terms' => '$1 Buyout Option',
                'speed_to_funds' => '3-10 Days',
                'icon' => 'ri-file-text-line',
                'icon_color' => 'blue',
                'sort_order' => 2,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Equipment', 'Technology', 'Medical Devices', 'Vehicles'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Equipment invoice or quote',
                    'Government-issued ID',
                ],
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Working Capital Equipment'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $catEquip->id,
                'subtitle' => 'Equipment financing bundled with working capital',
                'description' => 'Finance equipment AND receive additional working capital in a single facility. Ideal for businesses that need to upgrade equipment while maintaining cash flow.',
                'amount_range' => '25000-500000',
                'terms' => '12-60 Months',
                'speed_to_funds' => '3-7 Days',
                'icon' => 'ri-briefcase-line',
                'icon_color' => 'purple',
                'sort_order' => 3,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Equipment', 'Working Capital', 'Inventory', 'Operations'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => $defaultDocs,
            ]
        );

        FundingSequencing::updateOrCreate(
            ['name' => 'Transportation Financing'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $catTransport->id,
                'subtitle' => 'Trucks, trailers & fleet financing solutions',
                'description' => 'Finance commercial vehicles, trucks, trailers, and transportation fleets. Expand your logistics capacity with competitive rates and long terms.',
                'amount_range' => '25000-1000000',
                'terms' => 'Up to 72 Months',
                'speed_to_funds' => '3-10 Days',
                'icon' => 'ri-truck-line',
                'icon_color' => 'orange',
                'sort_order' => 4,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Trucks', 'Trailers', 'Fleet', 'Transportation'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $catCRE->id,
                'subtitle' => 'Purchase, refinance, or renovate commercial properties',
                'description' => 'Long-term commercial real estate loans for purchasing or refinancing commercial properties. Competitive rates with up to 25-year amortization.',
                'amount_range' => '100000-10000000',
                'terms' => '10-25 Years',
                'speed_to_funds' => '30-60 Days',
                'icon' => 'ri-building-4-line',
                'icon_color' => 'green',
                'sort_order' => 1,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Property Purchase', 'Refinancing', 'Renovation', 'Investment'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $catBridge->id,
                'subtitle' => 'Short-term financing for real estate opportunities',
                'description' => 'Bridge loans provide short-term financing when you need to close quickly on a property before securing long-term financing.',
                'amount_range' => '50000-5000000',
                'terms' => '6-24 Months',
                'speed_to_funds' => '7-21 Days',
                'icon' => 'ri-links-line',
                'icon_color' => 'blue',
                'sort_order' => 2,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Property Acquisition', 'Bridge Financing', 'Quick Close'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $catFixFlip->id,
                'subtitle' => 'Finance your real estate investment & renovation projects',
                'description' => 'Short-term loans designed for real estate investors to purchase, renovate, and sell residential or commercial properties.',
                'amount_range' => '50000-3000000',
                'terms' => '6-18 Months',
                'speed_to_funds' => '7-14 Days',
                'icon' => 'ri-tools-line',
                'icon_color' => 'purple',
                'sort_order' => 3,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['Property Purchase', 'Renovation', 'Fix & Flip', 'Investment'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
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
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $catConst->id,
                'subtitle' => 'Ground-up construction financing for new development',
                'description' => 'Construction loans for ground-up development projects. Funds are disbursed in draws as construction milestones are completed.',
                'amount_range' => '100000-10000000',
                'terms' => '12-24 Months',
                'speed_to_funds' => '21-45 Days',
                'icon' => 'ri-user-settings-line',
                'icon_color' => 'orange',
                'sort_order' => 4,
                'status' => 'Active',
                'action_url' => null,
                'uses' => ['New Construction', 'Ground-Up Development', 'Land Development'],

                'readiness_factors' => $defaultReadiness,
                'missing_documents' => [
                    'Approved construction plans',
                    'Contractor agreement and budget',
                    'Land deed or purchase agreement',
                    'Last 2 years personal tax returns',
                    'Government-issued ID',
                ],
            ]
        );

        // ══════════════════════════════════════════════════════════════════════
        // GROUP 5 — Seed LenderVisibility Data Dynamically
        // ══════════════════════════════════════════════════════════════════════

        $lenderIds = Lender::pluck('id')->toArray();
        $catIds = LenderProductCategory::pluck('id')->toArray();

        $sequencingIds = FundingSequencing::orderBy('id')->pluck('id')->toArray();

        // 1. Use the actual real categories assigned to each lender perfectly!
        $lenderCategories = [];
        $allLenders = Lender::all();
        foreach ($allLenders as $l) {
            $cats = is_string($l->product_categories) ? json_decode($l->product_categories, true) : $l->product_categories;
            $lenderCategories[$l->id] = !empty($cats) && is_array($cats) ? $cats : [1];
        }

        $numLenders = count($lenderIds);
        $numCats = count($catIds);

        // 2. Define variation arrays for dynamic deterministic data
        $ficos = [400, 420, 450, 480, 500];
        $tibs = [1, 3, 6, 12, 24];
        $revenues = [4000, 4200, 4500, 4800, 5000]; // Realistic min revenues
        $depositRatios = [0.4, 0.5, 0.6, 0.75]; // Deposits as a % of revenue
        $depositCounts = [5, 8, 10, 12, 15];
        $balanceRatios = [0.1, 0.15, 0.2, 0.25]; // Daily balance as a % of monthly deposits

        foreach ($sequencingIds as $index => $actualSeqId) {
            $selectedLenders = [];
            
            if ($numLenders > 0) {
                $l1 = $lenderIds[($index * 2) % $numLenders];
                $l2 = $lenderIds[($index * 2 + 1) % $numLenders];
                
                if ($l1 == $l2 && $numLenders > 1) {
                    $l2 = $lenderIds[($index * 2 + 2) % $numLenders];
                }
                
                $selectedLenders = array_unique([$l1, $l2]);
            } else {
                $selectedLenders = [1]; // Fallback
            }

            foreach ($selectedLenders as $lenderId) {
                $catsForLender = $lenderCategories[$lenderId] ?? (!empty($catIds) ? [$catIds[0]] : [1]);
                
                foreach ($catsForLender as $catId) {
                    // Generate a consistent index based on lender and category
                    $hash = $lenderId * 100 + $catId;

                    $minRevenue = $revenues[$hash % count($revenues)];
                    $depRatio = $depositRatios[$hash % count($depositRatios)];
                    $minMonthlyDeposits = intval($minRevenue * $depRatio);
                    
                    $minDepositCount = $depositCounts[$hash % count($depositCounts)];
                    // Ensure realistic average deposit size
                    $minAvgDeposits = intval($minMonthlyDeposits / ($minDepositCount + 2)); 
                    
                    $balRatio = $balanceRatios[$hash % count($balanceRatios)];
                    $minAvgDailyBalance = intval($minMonthlyDeposits * $balRatio);

                    $visibilityData = [
                        'min_fico' => $ficos[$hash % count($ficos)],
                        'min_tib_months' => $tibs[$hash % count($tibs)],
                        'min_monthly_revenue' => $minRevenue,
                        'min_avg_deposits' => $minAvgDeposits,
                        'min_deposit_count' => $minDepositCount,
                        'min_monthly_deposits' => $minMonthlyDeposits,
                        'min_average_daily_balance' => $minAvgDailyBalance,
                        'allowed_states' => [],
                        'restricted_states' => [],
                        'allowed_industries' => [],
                        'restricted_industries' => [],
                    ];

                    LenderVisibility::updateOrCreate(
                        [
                            'lender_id' => $lenderId,
                            'funding_sequencing_id' => $actualSeqId,
                            'lender_product_category_id' => $catId
                        ],
                        $visibilityData
                    );
                }
            }

            FundingSequencing::where('id', $actualSeqId)->update([
                'require_completed_application' => 1,
                'require_documents_uploaded' => 1,
                'allowed_documents' => [1, 4],
                'manual_review_allowed' => 1,
                'exception_allowed' => 0,
            ]);
        }

        $this->command->info('✅ FundingSequencingSeeder completed — dynamically seeded visibility rules and requirements for all sequences.');
    }
}