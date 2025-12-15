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
                'order' => 0,
                'tasks' => [
                    ['title' => 'Active LLC/Corp', 'description' => 'Source: SOS / OpenCorporates'],
                    ['title' => 'EIN match', 'description' => 'Source: IRS / BAV'],
                    ['title' => 'Business address', 'description' => 'Source: USPS API'],
                    ['title' => 'Business phone', 'description' => 'Source: Aggregator + 411'],
                    ['title' => '411 listing', 'description' => 'Source: Google CSE / ListYourself'],
                    ['title' => 'Website + email', 'description' => 'Source: Public validation'],
                    ['title' => 'Business bank account', 'description' => 'Action: Upload verified'],
                    ['title' => 'D-U-N-S', 'description' => 'Source: D&B lookup'],
                    ['title' => 'Business identity match', 'description' => 'Source: NAV business profile (name, address, NAICS)'],
                ]
            ],
            [
                'name' => 'Tier 1',
                'title' => 'Starter Vendor Accounts',
                'subtitle' => 'Open your first tradelines with vendors that report to the business credit bureaus. These small starter accounts help establish your business’s first credit history and build your D-U-N-S file.',
                'description' => "Open your first tradelines with vendors that report to the business credit bureaus.\n\nEntry Criteria:\n- Tier 0 complete\n- NAV identity match\n- No derogatories\n\nCompletion Criteria:\n- 8–10 reporting tradelines (includes NAV reporting tradeline)\n- 90+ days reporting history\n- PAYDEX ≥ 80\n- Zero late payments",
                'order' => 1,
                'tasks' => [
                    ['title' => 'Crown Office Supplies', 'description' => 'Reports to: D&B / EX / EQ'],
                    ['title' => 'Uline', 'description' => 'Reports to: D&B / EX'],
                    ['title' => 'Quill', 'description' => 'Reports to: D&B'],
                    ['title' => 'Grainger', 'description' => 'Reports to: D&B'],
                    ['title' => 'Staples Business Advantage', 'description' => 'Reports to: EX'],
                    ['title' => 'Texas Instruments', 'description' => 'Reports to: D&B / EX / EQ'],
                    ['title' => 'Harbor Freight Commercial', 'description' => 'Reports to: EQ'],
                    ['title' => 'Creative Analytics', 'description' => 'Reports to: Reports'],
                    ['title' => 'Wise Business Plans', 'description' => 'Reports to: Reports'],
                    ['title' => 'Office Garner', 'description' => 'Reports to: Reports'],
                ]
            ],
            [
                'name' => 'Tier 2',
                'title' => 'Intermediate Vendors & Revolving Accounts',
                'subtitle' => 'Expand your credit profile with additional vendors and store/fleet accounts that report to multiple bureaus. This phase strengthens your payment history and boosts your credit depth.',
                'description' => "Expand your credit profile with additional vendors and store/fleet accounts.\n\nEntry Criteria:\n- Tier 1 complete\n- ≥5 Tier-1 vendor tradelines reporting\n- NAV profile stable\n\nCompletion Criteria:\n- 3–5 Tier-2 tradelines\n- 10–12 total reporting tradelines\n- No lates\n- NAV shows no negative activity",
                'order' => 2,
                'tasks' => [
                    ['title' => 'Home Depot Commercial', 'description' => 'Store Vendor'],
                    ['title' => 'Lowe’s Pro / Commercial', 'description' => 'Store Vendor'],
                    ['title' => 'Staples Business Advantage', 'description' => 'Revoving/Limit path'],
                    ['title' => 'Harbor Freight', 'description' => 'If unused in Tier 1'],
                    ['title' => 'HD Supply', 'description' => 'Store Vendor'],
                ]
            ],
            [
                'name' => 'Tier 3',
                'title' => 'Fleet & Vehicle',
                'subtitle' => 'Transition from vendor credit to revolving business credit cards, higher limit Net 55/60 terms, and equipment or fleet financing. This tier proves long term creditworthiness and payment reliability.',
                'description' => "Transition to higher limit Net 55/60 terms and fleet financing.\n\nEntry Criteria:\n- Tier 2 complete\n- ≥10 total tradelines\n- Clean 12-month history\n- NAV scores stable\n\nCompletion Criteria:\n- 2–4 reporting fleet tradelines\n- 14+ total reporting tradelines\n- No derogatories",
                'order' => 3,
                'tasks' => [
                    ['title' => 'Shell Fleet', 'description' => 'Fleet Vendor'],
                    ['title' => 'Exxon/Mobil (WEX)', 'description' => 'Fleet Vendor'],
                    ['title' => 'Chevron/Texaco (WEX)', 'description' => 'Fleet Vendor'],
                    ['title' => 'BP Business Solutions', 'description' => 'Fleet Vendor'],
                    ['title' => 'Fuelman', 'description' => 'Fleet Vendor'],
                    ['title' => 'WEX Universal', 'description' => 'Fleet Vendor'],
                ]
            ],
            [
                'name' => 'Tier 4',
                'title' => 'Bank & Institutional Lending',
                'subtitle' => 'Leverage your established credit history to access bank lines of credit, SBA loans, and corporate financing — often without a personal guarantee. This tier is where funding power meets independence.',
                'description' => "Access bank lines of credit and corporate financing.\n\nEntry Criteria:\n- Tier 3 complete\n- ≥14 verified reporting tradelines\n- No negative data in NAV or bureaus\n- Meets revenue/TIB underwriting thresholds\n\nCompletion Criteria:\n- PAYDEX ≥ 80\n- Healthy Experian/Equifax\n- 0 × 30-day lates\n- Responsible utilization\n- Fundability intact",
                'order' => 4,
                'tasks' => [
                    ['title' => 'American Express Business', 'description' => 'Issuer'],
                    ['title' => 'Capital One Business', 'description' => 'Issuer'],
                    ['title' => 'Chase', 'description' => 'Issuer'],
                    ['title' => 'U.S. Bank', 'description' => 'Issuer'],
                    ['title' => 'Bank of America', 'description' => 'Issuer'],
                    ['title' => 'Corpay One', 'description' => 'Issuer'],
                    ['title' => 'Ramp', 'description' => 'Issuer'],
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
