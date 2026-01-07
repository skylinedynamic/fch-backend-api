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
                'subtitle' => 'Establish the legal and structural essentials required for business credit readiness and financial compliance.',
                'order' => 1,
                'status' => 'Active',
                'tasks' => [
                    [
                        'title' => 'EIN Consistency & Identity Verification',
                        'subtitle' => 'Confirm that the business EIN is consistent with the legal business identity.'   ,
                        'url' => 'https://irs.gov',
                        'button_title' => 'Verify EIN',
                        'estimated_completion_days' => 1,
                        'order' => 1,
                        'is_mandatory' => true,
                        'manual_verification' => false,
                        'related_api_id' => 1,
                    ],
                    [
                        'title' => 'Business Address Verification',
                        'subtitle' => 'Confirm that the physical business address is valid and consistent across records.',
                        'url' => '',
                        'button_title' => 'Verify Address',
                        'estimated_completion_days' => 1,
                        'order' => 2,
                        'is_mandatory' => true,
                        'manual_verification' => false,
                        'related_api_id' => 1,
                    ],
                        [
                        'title' => 'Business Phone & Directory Listing',
                        'subtitle' => 'Confirm that the physical business address is valid and consistent across records.',
                        'url' => '',
                        'button_title' => 'Verify Phone',
                        'estimated_completion_days' => 1,
                        'order' => 3,
                        'is_mandatory' => true,
                        'manual_verification' => false,
                        'related_api_id' => 1,
                    ],
                    [   
                        'title' => 'Website & Professional Email Presence',
                        'subtitle' => 'Review the business website and email domain for professional presence and consistency.',
                        'url' => '',
                        'button_title' => 'Verify Website',
                        'estimated_completion_days' => 1,
                        'order' => 4,
                        'is_mandatory' => true,
                        'manual_verification' => true,
                        'related_api_id' => null,
                    ],
                    [
                        'title' => 'Business Banking Presence',
                        'subtitle' => 'Confirm that the business has an active business bank account established in the business name.',
                        'url' => '',
                        'button_title' => 'Verify Banking',
                        'estimated_completion_days' => 1,
                        'order' => 5,
                        'is_mandatory' => true,
                        'manual_verification' => false,
                        'related_api_id' => 1,
                    ],
                    [
                        'title' => 'D-U-N-S Number (Existence Check)',
                        'subtitle' => 'Check whether a D-U-N-S number exists for the business and confirm association.',
                        'url' => '',
                        'button_title' => 'Verify D-U-N-S',
                        'estimated_completion_days' => 1,
                        'order' => 6,
                        'is_mandatory' => true,
                        'manual_verification' => false,
                        'related_api_id' => 1,
                    ],
                    [
                        'title' => 'Business identity match',
                        'subtitle' => 'Confirm that business identity details are consistent across records and documentation.',
                        'url' => '',
                        'button_title' => 'Verify Identity',
                        'estimated_completion_days' => 1,
                        'order' => 7,
                        'is_mandatory' => true,
                        'manual_verification' => true,
                        'related_api_id' => null,
                    ],
                    [
                        'title' => 'Active Legal Entity Status',
                        'subtitle' => 'Confirm that the business is legally registered and active with the appropriate state authority.',
                        'url' => 'https://example.com/verify-llc',
                        'button_title' => 'Verify Status',
                        'estimated_completion_days' => 1,
                        'order' => 8,
                        'is_mandatory' => true,
                        'manual_verification' => true,
                        'related_api_id' => null,
                    ],
                ]
            ],
            [
                'name' => 'Tier 1',
                'title' => 'Starter Vendor Accounts',
                'subtitle' => 'Open your first tradelines with vendors that report to the business credit bureaus. These small starter accounts help establish your business’s first credit history and build your D-U-N-S file.',
                'order' => 2,
                'status' => 'Active',
                'tasks' => [
                    ['title' => 'Uline', 'subtitle' => 'Shipping supplies', 'logo' => 'Logos/Uline.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 1],
                    ['title' => 'Quill', 'subtitle' => 'Office Supplies', 'logo' => 'Logos/Quill Office Supplies.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 2],
                    ['title' => 'Grainger', 'subtitle' => 'Industrial / MRO', 'logo' => 'Logos/Grainger logo.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 3],
                    ['title' => 'Crown Office Supplies', 'subtitle' => 'Office Supplies', 'logo' => 'Logos/Crown Office Supplies.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 4],
                    ['title' => 'Global Industrial', 'subtitle' => 'Warehouse, Safety & Shipping', 'logo' => 'Logos/Global-Industrial logo.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 5],
                    ['title' => 'MSC Industrial Supply', 'subtitle' => 'Industrial / MRO', 'logo' => 'Logos/MSC Industrial logo.jpg', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 6],
                    ['title' => 'Seton', 'subtitle' => 'Safety & Compliance Supplies', 'logo' => 'Logos/Seton Logo.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 7],
                    ['title' => 'Office Garner', 'subtitle' => 'Office Supplies', 'logo' => 'Logos/Office Garner Logo.png', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 8],
                ]
            ],
            [
                'name' => 'Tier 2',
                'title' => 'Intermediate Vendors & Revolving Accounts',
                'subtitle' => 'Expand your credit profile with additional vendors and store/fleet accounts that report to multiple bureaus. This phase strengthens your payment history and boosts your credit depth.',
                'order' => 3,
                'status' => 'Active',
                'tasks' => [
                    ['title' => 'Home Depot Commercial', 'subtitle' => 'Building materials', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 1],
                    ['title' => 'Lowe’s Pro / Commercial', 'subtitle' => 'Home improvement', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 2],
                    ['title' => 'Staples Business Advantage', 'subtitle' => 'Revolving credit', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 3],
                    ['title' => 'Harbor Freight', 'subtitle' => 'Tools credit', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 4],
                    ['title' => 'HD Supply', 'subtitle' => 'Maintenance supplies', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 5],
                ]
            ],
            [
                'name' => 'Tier 3',
                'title' => 'Fleet & Vehicle',
                'subtitle' => 'Transition from vendor credit to revolving business credit cards, higher limit Net 55/60 terms, and equipment or fleet financing. This tier proves long term creditworthiness and payment reliability.',
                'order' => 4,
                'status' => 'Active',
                'tasks' => [
                    ['title' => 'Shell Fleet', 'subtitle' => 'Fuel card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 1],
                    ['title' => 'Exxon/Mobil (WEX)', 'subtitle' => 'Fuel card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 2],
                    ['title' => 'Chevron/Texaco (WEX)', 'subtitle' => 'Fuel card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 3],
                    ['title' => 'BP Business Solutions', 'subtitle' => 'Fuel card','url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 4],
                    ['title' => 'Fuelman', 'subtitle' => 'Fuel management', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 5],
                    ['title' => 'WEX Universal', 'subtitle' => 'Universal fleet card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 6],
                ]
            ],
            [
                'name' => 'Tier 4',
                'title' => 'Bank & Institutional Lending',
                'subtitle' => 'Leverage your established credit history to access bank lines of credit, SBA loans, and corporate financing — often without a personal guarantee. This tier is where funding power meets independence.',
                'order' => 5,
                'status' => 'Active',
                'tasks' => [
                    ['title' => 'American Express Business', 'subtitle' => 'Business Card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 1],
                    ['title' => 'Capital One Business', 'subtitle' => 'Credit Card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 2],
                    ['title' => 'Chase', 'subtitle' => 'Business Banking', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 3],
                    ['title' => 'U.S. Bank', 'subtitle' => 'Business Solutions', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 4],
                    ['title' => 'Bank of America', 'subtitle' => 'Corporate Banking', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 5],
                    ['title' => 'Corpay One', 'subtitle' => 'Payment Solutions', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 6],
                    ['title' => 'Ramp', 'subtitle' => 'Corporate Card', 'url' => '', 'button_title' => 'Apply Now', 'estimated_completion_days' => 30, 'order' => 7],
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
