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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
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
                'description' => `<div>
<div class="alert alert-warning d-flex align-items-start gap-2 border-0 rounded-3 mb-3 p-3 task-modal-alert"><i class="ri-alert-fill text-warning fs-5 mt-0"></i><div class="text-dark task-modal-alert-text"><strong>Important:</strong> Read these instructions carefully before proceeding.</div></div>
<div class="task-content"><div class="styled-content"><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Eligibility</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">It's recommended that your business is at least 30 days old.</li><li class="mb-1">Use your exact business legal name, address, and information when applying.</li><li class="mb-1">Orders should be consistent with normal business purchases; do not order luxury items, gift cards, or personal items.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Ordering Guidelines</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">Place an initial order of at least $70 worth of shipping, packaging, or cleaning supplies.</li><li class="mb-1">Always make payments on <span class="fw-bold text-black">time</span> to build a positive payment history.</li></ul><h6 class="fw-bold mb-1 text-dark task-modal-section-title">Reporting Timeline</h6><ul class="mb-3 text-dark ps-3 task-modal-list"><li class="mb-1">After making a <span class="fw-bold text-black">purchase</span>, reporting to bureaus typically occurs after two or more on-time payment cycles.</li></ul><hr class="my-3 opacity-25"><p class="text-dark fst-italic mb-0 task-modal-footer-note">Opening an account and making purchases does not guarantee credit reporting. Accounts are reviewed before reporting.</p></div></div>
</div>`,
                'affiliate_url' => '',
                'general_url' => 'https://www.homedepot.com/c/pro',
                'logo' => 'logos/tier-2/home-depot.png',
                'report_to' => ['D&B', 'Experian', 'Equifax'],
                'tier_id' => $tier2,
                'status' => CreditBuilderVendor::STATUS_ACTIVE,
            ]
        ];

        foreach ($vendors as $vendor) {
            CreditBuilderVendor::create($vendor);
        }
    }
}
