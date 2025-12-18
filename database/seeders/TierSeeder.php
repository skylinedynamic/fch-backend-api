<?php

namespace Database\Seeders;

use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data to prevent duplicates
        // Disable foreign key checks to allow truncation
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        TierTask::truncate();
        Tier::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $tiers = [
            [
                'name' => 'Tier 0',
                'title' => 'Business Foundation & Compliance',
                'subtitle' => 'Lay the groundwork for fundability. This phase ensures your business is properly formed, verified, and listed — including EIN registration, business phone, 411 listing, professional email, and virtual address setup. Lenders can’t approve what they can’t verify.',
                'description' => "Establish the legal and structural essentials required for business credit readiness and financial compliance.\n\nAdvancement Requirement: All items must be PASSED.",
                'order' => 1,
                'status' => 'Active',
                'tasks' => [
                    [
                        'title' => 'Active LLC/Corp', 
                        'subtitle' => 'Verify your entity status', 
                        'description' => 'Source: SOS / OpenCorporates', 
                        'url' => 'https://example.com/verify-llc', 
                        'button_title' => 'Verify LLC', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'EIN match', 
                        'subtitle' => 'Check EIN with IRS', 
                        'description' => 'Source: IRS / BAV', 
                        'url' => 'https://irs.gov', 
                        'button_title' => 'Check EIN', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Business address', 
                        'subtitle' => 'Confirm physical address', 
                        'description' => 'Source: USPS API', 
                        'url' => '', 
                        'button_title' => 'Verify Address', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Business phone', 
                        'subtitle' => 'List business number', 
                        'description' => 'Source: Aggregator + 411', 
                        'url' => '', 
                        'button_title' => 'Update Phone', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => '411 listing', 
                        'subtitle' => 'Ensure directory listing', 
                        'description' => 'Source: Google CSE / ListYourself', 
                        'url' => '', 
                        'button_title' => 'Check Listing', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Website + email', 
                        'subtitle' => 'Professional web presence', 
                        'description' => 'Source: Public validation', 
                        'url' => '', 
                        'button_title' => 'Visit Site', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Business bank account', 
                        'subtitle' => 'Active banking', 
                        'description' => 'Action: Upload verified', 
                        'url' => '', 
                        'button_title' => 'Upload', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'D-U-N-S', 
                        'subtitle' => 'Dun & Bradstreet Number', 
                        'description' => 'Source: D&B lookup', 
                        'url' => '', 
                        'button_title' => 'Find D-U-N-S', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Business identity match', 
                        'subtitle' => 'Consistent profile', 
                        'description' => 'Source: NAV business profile (name, address, NAICS)', 
                        'url' => '', 
                        'button_title' => 'Check Profile', 
                        'estimated_completion_days' => 1,
                        'is_mandatory' => true, 
                    ],
                ]
            ],
            [
                'name' => 'Tier 1',
                'title' => 'Starter Vendor Accounts',
                'subtitle' => 'Open your first tradelines with vendors that report to the business credit bureaus. These small starter accounts help establish your business’s first credit history and build your D-U-N-S file.',
                'description' => "Open your first tradelines with vendors that report to the business credit bureaus.\n\nEntry Criteria:\n- Tier 0 complete\n- NAV identity match\n- No derogatories\n\nCompletion Criteria:\n- 8–10 reporting tradelines (includes NAV reporting tradeline)\n- 90+ days reporting history\n- PAYDEX ≥ 80\n- Zero late payments",
                'order' => 2,
                'status' => 'Active',
                'tasks' => [
                    [
                        'title' => 'Crown Office Supplies', 
                        'subtitle' => 'Office essentials', 
                        'description' => 'Reports to: D&B / EX / EQ', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Uline', 
                        'subtitle' => 'Shipping supplies', 
                        'description' => 'Reports to: D&B / EX', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Quill', 
                        'subtitle' => 'Office products', 
                        'description' => 'Reports to: D&B', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,
                    ],
                    [
                        'title' => 'Grainger', 
                        'subtitle' => 'Industrial supplies', 
                        'description' => 'Reports to: D&B', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Staples Business Advantage', 
                        'subtitle' => 'Business solutions', 
                        'description' => 'Reports to: EX', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Texas Instruments', 
                        'subtitle' => 'Technical equipment', 
                        'description' => 'Reports to: D&B / EX / EQ', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,     
                    ],
                    [
                        'title' => 'Harbor Freight Commercial', 
                        'subtitle' => 'Tools and equipment', 
                        'description' => 'Reports to: EQ', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Creative Analytics', 
                        'subtitle' => 'Data services', 
                        'description' => 'Reports to: Reports', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Wise Business Plans', 
                        'subtitle' => 'Planning services', 
                        'description' => 'Reports to: Reports', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Office Garner', 
                        'subtitle' => 'Office supplies', 
                        'description' => 'Reports to: Reports', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                ]
            ],
            [
                'name' => 'Tier 2',
                'title' => 'Intermediate Vendors & Revolving Accounts',
                'subtitle' => 'Expand your credit profile with additional vendors and store/fleet accounts that report to multiple bureaus. This phase strengthens your payment history and boosts your credit depth.',
                'description' => "Expand your credit profile with additional vendors and store/fleet accounts.\n\nEntry Criteria:\n- Tier 1 complete\n- ≥5 Tier-1 vendor tradelines reporting\n- NAV profile stable\n\nCompletion Criteria:\n- 3–5 Tier-2 tradelines\n- 10–12 total reporting tradelines\n- No lates\n- NAV shows no negative activity",
                'order' => 3,
                'status' => 'Active',
                'tasks' => [
                    [
                        'title' => 'Home Depot Commercial', 
                        'subtitle' => 'Building materials', 
                        'description' => 'Store Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Lowe’s Pro / Commercial', 
                        'subtitle' => 'Home improvement', 
                        'description' => 'Store Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,     
                    ],
                    [
                        'title' => 'Staples Business Advantage', 
                        'subtitle' => 'Revolving credit', 
                        'description' => 'Revoving/Limit path', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Harbor Freight', 
                        'subtitle' => 'Tools credit', 
                        'description' => 'If unused in Tier 1', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'HD Supply', 
                        'subtitle' => 'Maintenance supplies', 
                        'description' => 'Store Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                ]
            ],
            [
                'name' => 'Tier 3',
                'title' => 'Fleet & Vehicle',
                'subtitle' => 'Transition from vendor credit to revolving business credit cards, higher limit Net 55/60 terms, and equipment or fleet financing. This tier proves long term creditworthiness and payment reliability.',
                'description' => "Transition to higher limit Net 55/60 terms and fleet financing.\n\nEntry Criteria:\n- Tier 2 complete\n- ≥10 total tradelines\n- Clean 12-month history\n- NAV scores stable\n\nCompletion Criteria:\n- 2–4 reporting fleet tradelines\n- 14+ total reporting tradelines\n- No derogatories",
                'order' => 4,
                'status' => 'Active',
                'tasks' => [
                    [
                            'title' => 'Shell Fleet', 
                        'subtitle' => 'Fuel card', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true, 
                    ],
                    [
                        'title' => 'Exxon/Mobil (WEX)', 
                        'subtitle' => 'Fuel card', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,     
                    ],
                    [
                        'title' => 'Chevron/Texaco (WEX)', 
                        'subtitle' => 'Fuel card', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,       
                    ],
                    [
                        'title' => 'BP Business Solutions', 
                        'subtitle' => 'Fuel card', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,       
                    ],
                    [
                        'title' => 'Fuelman', 
                        'subtitle' => 'Fuel management', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,       
                    ],
                    [
                        'title' => 'WEX Universal', 
                        'subtitle' => 'Universal fleet card', 
                        'description' => 'Fleet Vendor', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,       
                    ],
                ]
            ],
            [
                'name' => 'Tier 4',
                'title' => 'Bank & Institutional Lending',
                'subtitle' => 'Leverage your established credit history to access bank lines of credit, SBA loans, and corporate financing — often without a personal guarantee. This tier is where funding power meets independence.',
                'description' => "Access bank lines of credit and corporate financing.\n\nEntry Criteria:\n- Tier 3 complete\n- ≥14 verified reporting tradelines\n- No negative data in NAV or bureaus\n- Meets revenue/TIB underwriting thresholds\n\nCompletion Criteria:\n- PAYDEX ≥ 80\n- Healthy Experian/Equifax\n- 0 × 30-day lates\n- Responsible utilization\n- Fundability intact",
                'order' => 5,
                'status' => 'Active',
                'tasks' => [
                    [
                        'title' => 'American Express Business', 
                        'subtitle' => 'Business Card', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Capital One Business', 
                        'subtitle' => 'Credit Card', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Chase', 
                        'subtitle' => 'Business Banking', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'U.S. Bank', 
                        'subtitle' => 'Business Solutions', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Bank of America', 
                        'subtitle' => 'Corporate Banking', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Corpay One', 
                        'subtitle' => 'Payment Solutions', 
                        'description' => 'Issuer', 
                        'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                    [
                        'title' => 'Ramp', 
                        'subtitle' => 'Corporate Card', 
                        'description' => 'Issuer', 'url' => '', 
                        'button_title' => 'Apply Now', 
                        'estimated_completion_days' => 30,
                        'is_mandatory' => true,         
                    ],
                ]
            ],
        ];

        foreach ($tiers as $tierData) {
            $tasks = $tierData['tasks'];
            unset($tierData['tasks']);

            $tier = Tier::create($tierData);

            foreach ($tasks as $taskData) {
                $tier->tasks()->create($taskData);
            }
        }
    }
}
