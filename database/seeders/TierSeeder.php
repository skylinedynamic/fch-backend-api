<?php

namespace Database\Seeders;

use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class TierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure logos are copied from public/logos to public/storage/logos
        $sourcePath = public_path('logos');
        $destinationPath = public_path('storage/logos');

        if (File::isDirectory($sourcePath)) {
            if (!File::isDirectory($destinationPath)) {
                File::makeDirectory($destinationPath, 0755, true, true);
            }
            File::copyDirectory($sourcePath, $destinationPath);
        }

        // Clear existing data to prevent duplicates
        // Disable foreign key checks to allow truncation
        Schema::disableForeignKeyConstraints();
        TierTask::truncate();
        Tier::truncate();
        Schema::enableForeignKeyConstraints();

        $tiers = [
            [
                'name' => 'Tier 0',
                'code' => 'T0',
                'title' => 'Business Foundation & Compliance',
                'subtitle' => 'Establish the legal and structural essentials required for business credit readiness and financial compliance.',
                'order' => 1,
                'has_vendor' => false,
                'task_to_complete' => null,
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
                'code' => 'T1',
                'title' => 'Starter Vendor Credit (BUILD)',
                'subtitle' => 'Open your first tradelines with vendors that report to the business credit bureaus. These small starter accounts help establish your business’s first credit history and build your D-U-N-S file.',
                'order' => 2,
                'task_to_complete' => 1,
                'has_vendor' => true,
                'status' => 'Active',
                'tasks' => [
                    ['title' => 'Uline', 'subtitle' => 'Shipping Supplies', 'logo' => 'logos/tier-1/uline.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 1, 'vendor_id' => 1, 'manual_verification' => true],
                    ['title' => 'Quill', 'subtitle' => 'Office Supplies', 'logo' => 'logos/tier-1/quill.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 2, 'vendor_id' => 2, 'manual_verification' => true],
                    ['title' => 'Grainger', 'subtitle' => 'Industrial / MRO', 'logo' => 'logos/tier-1/grainger_logo.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 3, 'vendor_id' => 3, 'manual_verification' => true],
                    ['title' => 'Crown Office Supplies', 'subtitle' => 'Office Supplies', 'logo' => 'logos/tier-1/crown.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 4, 'vendor_id' => 5, 'manual_verification' => true],
                    ['title' => 'Global Industrial', 'subtitle' => 'Warehouse, Safety & Shipping', 'logo' => 'logos/tier-1/global_logo.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 5, 'vendor_id' => 6, 'manual_verification' => true],
                    ['title' => 'MSC Industrial Supply', 'subtitle' => 'Industrial / MRO', 'logo' => 'logos/tier-1/msc.jpg', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 6, 'vendor_id' => 4, 'manual_verification' => true],
                    ['title' => 'Seton', 'subtitle' => 'Safety & Compliance Supplies', 'logo' => 'logos/tier-1/seton_logo.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 7, 'vendor_id' => 7, 'manual_verification' => true],
                    ['title' => 'Office Garner', 'subtitle' => 'Office Supplies', 'logo' => 'logos/tier-1/office_garner_logo.png', 'url' => '', 'button_title' => 'Register Now', 'estimated_completion_days' => 30, 'order' => 8, 'vendor_id' => 8, 'manual_verification' => true],
                ]
            ],
            [
                'name' => 'Tier 2',
                'title' => 'Aging & Validation (AGE)',
                'code' => 'T2',
                'subtitle' => 'Let the credit mature without damaging the file.',
                'order' => 3,
                'task_to_complete' => 1,
                'has_vendor' => false,
                'status' => 'Active',
                'tasks' => []
            ],
            [
                'name' => 'Tier 3',
                'title' => 'Controlled Expansion (EXPAND)',
                'code' => 'T3',
                'subtitle' => 'Selective growth without blowing up the profile',
                'order' => 4,
                'task_to_complete' => 1,
                'has_vendor' => false,
                'status' => 'Active',
                'tasks' => []
            ],
            [
                'name' => 'Tier 4',
                'title' => 'Institutional Readiness (EVALUATE)',
                'code' => 'T4',
                'subtitle' => 'Decide if the file is lender-ready',
                'order' => 5,
                'task_to_complete' => 1,
                'has_vendor' => false,
                'status' => 'Active',
                'tasks' => []
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
