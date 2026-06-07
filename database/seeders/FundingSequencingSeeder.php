<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingType;
use App\Models\Funding\FundingSequencing;

class FundingSequencingSeeder extends Seeder
{
    public function run(): void
    {
        // Types
        $typeCreditCards = FundingType::firstOrCreate(['name' => 'Credit Cards'], ['sort_order' => 1]);
        $typeWorkingCapital = FundingType::firstOrCreate(['name' => 'Working Capital'], ['sort_order' => 2]);
        $typeEquipment = FundingType::firstOrCreate(['name' => 'Equipment'], ['sort_order' => 3]);
        $typeRealEstate = FundingType::firstOrCreate(['name' => 'Real Estate'], ['sort_order' => 4]);
        
        FundingType::firstOrCreate(['name' => 'SBA Loans'], ['sort_order' => 5]);
        FundingType::firstOrCreate(['name' => 'Lines of Credit'], ['sort_order' => 6]);
        FundingType::firstOrCreate(['name' => 'Term Loans'], ['sort_order' => 7]);
        FundingType::firstOrCreate(['name' => 'Invoice Financing'], ['sort_order' => 8]);

        // Groups
        $groupCredit = FundingGroup::firstOrCreate(['name' => 'Business Credit & Credit Cards'], ['sort_order' => 1]);
        $groupWorking = FundingGroup::firstOrCreate(['name' => 'Working Capital Solutions'], ['sort_order' => 2]);
        $groupEquipment = FundingGroup::firstOrCreate(['name' => 'Equipment Financing'], ['sort_order' => 3]);
        $groupRealEstate = FundingGroup::firstOrCreate(['name' => 'Real Estate Financing'], ['sort_order' => 4]);

        // Sequencings for Credit Cards
        FundingSequencing::firstOrCreate(
            ['name' => '0% Intro Business Cards'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $typeCreditCards->id,
                'subtitle' => 'Low / No personal guarantee',
                'description' => 'Credit cards with 0% intro APR',
                'amount_range' => '$5K - $250K',
                'terms' => '0% APR',
                'icon' => 'ri-bank-card-line',
                'icon_color' => 'green',
                'sort_order' => 1
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Business Credit Builder Cards'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $typeCreditCards->id,
                'subtitle' => 'Build business credit while receiving funding',
                'amount_range' => '$2K - $100K',
                'terms' => 'Net 30',
                'icon' => 'ri-bank-card-line',
                'icon_color' => 'blue',
                'sort_order' => 2
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Premium Rewards Cards'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $typeCreditCards->id,
                'subtitle' => 'High limit cards with premium rewards and benefits',
                'amount_range' => '$10K - $500K',
                'terms' => 'Rewards',
                'icon' => 'ri-star-line',
                'icon_color' => 'purple',
                'sort_order' => 3
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Vendor Credit Lines'],
            [
                'funding_group_id' => $groupCredit->id,
                'funding_type_id' => $typeCreditCards->id,
                'subtitle' => 'Net 30, 60, 90 with major vendors and suppliers',
                'amount_range' => '$1K - $250K',
                'terms' => 'Net Terms',
                'icon' => 'ri-shopping-cart-2-line',
                'icon_color' => 'orange',
                'sort_order' => 4
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );

        // Sequencings for Working Capital
        FundingSequencing::firstOrCreate(
            ['name' => 'Business Lines of Credit'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $typeWorkingCapital->id,
                'subtitle' => 'Revolving credit lines for your business needs',
                'amount_range' => '10000-250000',
                'terms' => 'Revolving',
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'icon' => 'ri-refresh-line',
                'icon_color' => 'green',
                'sort_order' => 1,
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false
            ]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Term Loans'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $typeWorkingCapital->id,
                'subtitle' => 'Fixed payments for business expansion and growth',
                'amount_range' => '$25K - $5M',
                'terms' => '6-60 Months',
                'icon' => 'ri-bank-line',
                'icon_color' => 'blue',
                'sort_order' => 2
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Merchant Cash Advance'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $typeWorkingCapital->id,
                'subtitle' => 'Fast funding based on future credit card sales',
                'amount_range' => '$5K - $500K',
                'terms' => 'Fast Approval',
                'icon' => 'ri-camera-line',
                'icon_color' => 'purple',
                'sort_order' => 3
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Revenue Based Financing'],
            [
                'funding_group_id' => $groupWorking->id,
                'funding_type_id' => $typeWorkingCapital->id,
                'subtitle' => 'Flexible funding based on monthly revenue',
                'amount_range' => '$10K - $3M',
                'terms' => 'Flexible',
                'icon' => 'ri-line-chart-line',
                'icon_color' => 'orange',
                'sort_order' => 4
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );

        // Sequencings for Equipment Financing
        FundingSequencing::firstOrCreate(
            ['name' => 'Equipment Loans'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $typeEquipment->id,
                'subtitle' => 'Finance new or used equipment with competitive rates',
                'amount_range' => '$10K - $2M',
                'terms' => 'Up to 84 Months',
                'icon' => 'ri-settings-3-line',
                'icon_color' => 'green',
                'sort_order' => 1
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Equipment Leasing'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $typeEquipment->id,
                'subtitle' => 'Preserve cash flow with equipment leasing solutions',
                'amount_range' => '$5K - $1M',
                'terms' => '$1 Buyout',
                'icon' => 'ri-file-text-line',
                'icon_color' => 'blue',
                'sort_order' => 2
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Working Capital Equipment'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $typeEquipment->id,
                'subtitle' => 'Equipment financing with working capital',
                'amount_range' => '$25K - $500K',
                'terms' => 'Flexible',
                'icon' => 'ri-briefcase-line',
                'icon_color' => 'purple',
                'sort_order' => 3
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Transportation Financing'],
            [
                'funding_group_id' => $groupEquipment->id,
                'funding_type_id' => $typeEquipment->id,
                'subtitle' => 'Trucks, trailers & transportation equipment financing',
                'amount_range' => '$25K - $1M',
                'terms' => 'Up to 72 Months',
                'icon' => 'ri-truck-line',
                'icon_color' => 'orange',
                'sort_order' => 4
            ,
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );

        // Sequencings for Real Estate Financing
        FundingSequencing::firstOrCreate(
            ['name' => 'Commercial Real Estate'],
            [
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $typeRealEstate->id,
                'subtitle' => 'Purchase, refinance, or renovate commercial properties',
                'icon' => 'ri-building-4-line',
                'icon_color' => 'green',
                'sort_order' => 1
            ,
                'amount_range' => '$5K - $500K',
                'terms' => 'Flexible',
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Bridge Loans'],
            [
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $typeRealEstate->id,
                'subtitle' => 'Short-term financing for real estate opportunities',
                'icon' => 'ri-links-line',
                'icon_color' => 'blue',
                'sort_order' => 2
            ,
                'amount_range' => '$5K - $500K',
                'terms' => 'Flexible',
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Fix & Flip Loans'],
            [
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $typeRealEstate->id,
                'subtitle' => 'Finance your real estate investment projects',
                'icon' => 'ri-tools-line',
                'icon_color' => 'purple',
                'sort_order' => 3
            ,
                'amount_range' => '$5K - $500K',
                'terms' => 'Flexible',
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
        FundingSequencing::firstOrCreate(
            ['name' => 'Construction Loans'],
            [
                'funding_group_id' => $groupRealEstate->id,
                'funding_type_id' => $typeRealEstate->id,
                'subtitle' => 'Ground-up construction financing solutions',
                'icon' => 'ri-user-settings-line',
                'icon_color' => 'orange',
                'sort_order' => 4
            ,
                'amount_range' => '$5K - $500K',
                'terms' => 'Flexible',
                'speed_to_funds' => '1-7',
                'uses' => ['Working Capital', 'Inventory', 'Payroll'],
                'qualify_reasons' => [
                    'Revenue exceeds minimum threshold',
                    'Time in business exceeds requirement',
                    'Business profile meets our lending standards',
                    'Banking activity shows consistent deposits'
                ],
                'readiness_factors' => [
                    ['label' => 'Business Credit', 'status' => 'Good'],
                    ['label' => 'Revenue', 'status' => 'Strong'],
                    ['label' => 'Time in Business', 'status' => 'Strong'],
                    ['label' => 'Banking Activity', 'status' => 'Good'],
                    ['label' => 'Existing Debt', 'status' => 'Good'],
                    ['label' => 'Documents', 'status' => 'Missing']
                ],
                'missing_documents' => [
                    'Last 3 months business bank statements',
                    'Voided business check'
                ],
                'action_url' => null,
                'min_fico' => 656,
                'min_tib_months' => 65665,
                'min_monthly_revenue' => 65665.00,
                'min_monthly_deposits' => 788788.00,
                'min_average_daily_balance' => 8755454.00,
                'max_nsf_count' => 54553,
                'max_negative_days' => 34323,
                'min_dscr' => 660.00,
                'allowed_states' => ['AZ', 'AR'],
                'restricted_states' => ['AL', 'AK', 'AZ'],
                'allowed_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'restricted_industries' => ['Accounting & Financial Services', 'Automotive Services & Dealerships', 'Beauty, Salon & Spa Services'],
                'require_completed_application' => false,
                'require_documents_uploaded' => true,
                'manual_review_allowed' => true,
                'exception_allowed' => false]
        );
    }
}
