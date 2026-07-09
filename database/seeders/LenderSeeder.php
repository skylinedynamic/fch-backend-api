<?php

namespace Database\Seeders;

use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LenderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $lenders = [
            [
                'id' => 1,
                'name' => 'Bluevine Capital',
                'legal_name' => 'Bluevine Inc.',
                'contact_name' => 'Sarah Johnson',
                'contact_title' => 'Partner Success Manager',
                'contact_email' => 'partners@bluevine.dummy',
                'contact_phone' => '(888) 555-0101',
                'website' => 'https://www.bluevine.dummy',
                'submission_method' => 'Portal',
                'submission_email' => 'submissions@bluevine.dummy',
                'submission_portal_link' => 'https://portal.bluevine.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Preferred partner for fast lines of credit.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 2,
                'name' => 'Fundbox Financing',
                'legal_name' => 'Fundbox LLC',
                'contact_name' => 'Michael Chen',
                'contact_title' => 'Account Executive',
                'contact_email' => 'mchen@fundbox.dummy',
                'contact_phone' => '(888) 555-0102',
                'website' => 'https://www.fundbox.dummy',
                'submission_method' => 'API',
                'submission_email' => null,
                'submission_portal_link' => 'https://api.fundbox.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Great for short-term term loans.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 3,
                'name' => 'Credibly',
                'legal_name' => 'Credibly Financial',
                'contact_name' => 'David Montoya',
                'contact_title' => 'Senior Underwriter',
                'contact_email' => 'david.m@credibly.dummy',
                'contact_phone' => '(888) 555-0103',
                'website' => 'https://www.credibly.dummy',
                'submission_method' => 'Email',
                'submission_email' => 'deals@credibly.dummy',
                'submission_portal_link' => null,
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Accepts high-risk MCA profiles.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 4,
                'name' => 'OnDeck',
                'legal_name' => 'OnDeck Capital',
                'contact_name' => 'Jessica Roberts',
                'contact_title' => 'ISO Relations',
                'contact_email' => 'iso@ondeck.dummy',
                'contact_phone' => '(888) 555-0104',
                'website' => 'https://www.ondeck.dummy',
                'submission_method' => 'Partner Portal',
                'submission_email' => 'submit@ondeck.dummy',
                'submission_portal_link' => 'https://partners.ondeck.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => null,
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 5,
                'name' => 'LendingClub',
                'legal_name' => 'LendingClub Bank',
                'contact_name' => 'Kevin Smith',
                'contact_title' => 'SBA Specialist',
                'contact_email' => 'ksmith@lendingclub.dummy',
                'contact_phone' => '(888) 555-0105',
                'website' => 'https://www.lendingclub.dummy',
                'submission_method' => 'Broker Portal',
                'submission_email' => null,
                'submission_portal_link' => 'https://broker.lendingclub.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Standard SBA 7(a) and 504 loans.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 6,
                'name' => 'Balboa Capital',
                'legal_name' => 'Balboa Capital Corporation',
                'contact_name' => 'Amanda Lewis',
                'contact_title' => 'Equipment Finance Manager',
                'contact_email' => 'alewis@balboa.dummy',
                'contact_phone' => '(888) 555-0106',
                'website' => 'https://www.balboacapital.dummy',
                'submission_method' => 'Email',
                'submission_email' => 'equipment@balboa.dummy',
                'submission_portal_link' => null,
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Fastest equipment leasing approvals.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 7,
                'name' => 'Lendio',
                'legal_name' => 'Lendio Inc.',
                'contact_name' => 'Chris Anderson',
                'contact_title' => 'Strategic Partnerships',
                'contact_email' => 'partners@lendio.dummy',
                'contact_phone' => '(888) 555-0107',
                'website' => 'https://www.lendio.dummy',
                'submission_method' => 'API',
                'submission_email' => null,
                'submission_portal_link' => 'https://api.lendio.dummy/submit',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Good for new businesses.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 8,
                'name' => 'National Funding',
                'legal_name' => 'National Funding Inc.',
                'contact_name' => 'Samantha Wright',
                'contact_title' => 'Funding Coordinator',
                'contact_email' => 'swright@nationalfunding.dummy',
                'contact_phone' => '(888) 555-0108',
                'website' => 'https://www.nationalfunding.dummy',
                'submission_method' => 'Phone',
                'submission_email' => 'apps@nationalfunding.dummy',
                'submission_portal_link' => null,
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => null,
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 9,
                'name' => 'Kiavi',
                'legal_name' => 'Kiavi Funding',
                'contact_name' => 'Robert Taylor',
                'contact_title' => 'Real Estate Executive',
                'contact_email' => 'rtaylor@kiavi.dummy',
                'contact_phone' => '(888) 555-0109',
                'website' => 'https://www.kiavi.dummy',
                'submission_method' => 'Portal',
                'submission_email' => null,
                'submission_portal_link' => 'https://portal.kiavi.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Top tier for real estate investors.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 10,
                'name' => 'Clearco',
                'legal_name' => 'Clearbanc',
                'contact_name' => 'Emily White',
                'contact_title' => 'Growth Capital Partner',
                'contact_email' => 'ewhite@clear.co',
                'contact_phone' => '(888) 555-0110',
                'website' => 'https://www.clear.co',
                'submission_method' => 'API',
                'submission_email' => null,
                'submission_portal_link' => 'https://api.clear.co/partners',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'E-commerce and SaaS specialty.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 11,
                'name' => 'Rapid Finance',
                'legal_name' => 'Rapid Finance LLC',
                'contact_name' => 'Alex Turner',
                'contact_title' => 'Funding Advisor',
                'contact_email' => 'aturner@rapidfinance.dummy',
                'contact_phone' => '(888) 555-0111',
                'website' => 'https://www.rapidfinance.dummy',
                'submission_method' => 'Email',
                'submission_email' => 'submissions@rapidfinance.dummy',
                'submission_portal_link' => null,
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Fastest MCA approvals.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 12,
                'name' => 'Smarter Loans',
                'legal_name' => 'Smarter Loans Inc.',
                'contact_name' => 'Linda Scott',
                'contact_title' => 'Senior Underwriter',
                'contact_email' => 'lscott@smarterloans.dummy',
                'contact_phone' => '(888) 555-0112',
                'website' => 'https://www.smarterloans.dummy',
                'submission_method' => 'API',
                'submission_email' => null,
                'submission_portal_link' => 'https://api.smarterloans.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Great for established businesses.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 13,
                'name' => 'Kabbage',
                'legal_name' => 'Kabbage Inc.',
                'contact_name' => 'Thomas Lee',
                'contact_title' => 'Partnership Manager',
                'contact_email' => 'tlee@kabbage.dummy',
                'contact_phone' => '(888) 555-0113',
                'website' => 'https://www.kabbage.dummy',
                'submission_method' => 'Portal',
                'submission_email' => null,
                'submission_portal_link' => 'https://partners.kabbage.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Excellent for quick LOC.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 14,
                'name' => 'Kapitus',
                'legal_name' => 'Kapitus Financial',
                'contact_name' => 'Rachel Adams',
                'contact_title' => 'ISO Manager',
                'contact_email' => 'radams@kapitus.dummy',
                'contact_phone' => '(888) 555-0114',
                'website' => 'https://www.kapitus.dummy',
                'submission_method' => 'Email',
                'submission_email' => 'submit@kapitus.dummy',
                'submission_portal_link' => null,
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Good alternative to Balboa.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 15,
                'name' => 'Forward Financing',
                'legal_name' => 'Forward Financing LLC',
                'contact_name' => 'Daniel White',
                'contact_title' => 'Funding Specialist',
                'contact_email' => 'dwhite@forwardfinancing.dummy',
                'contact_phone' => '(888) 555-0115',
                'website' => 'https://www.forwardfinancing.dummy',
                'submission_method' => 'Portal',
                'submission_email' => null,
                'submission_portal_link' => 'https://broker.forwardfinancing.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Flexible requirements.',
                'last_verified_date' => '2026-06-11',
            ],
            [
                'id' => 16,
                'name' => 'Amex Business',
                'legal_name' => 'American Express Bank',
                'contact_name' => 'Laura Perez',
                'contact_title' => 'Business Card Specialist',
                'contact_email' => 'lperez@amex.dummy',
                'contact_phone' => '(888) 555-0116',
                'website' => 'https://www.americanexpress.dummy',
                'submission_method' => 'API',
                'submission_email' => null,
                'submission_portal_link' => 'https://api.amex.dummy',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => 'Premium rewards cards.',
                'last_verified_date' => '2026-06-11',
            ],
        ];

        $categoryIds = LenderProductCategory::pluck('id')->toArray();
        
        $lenderCount = count($lenders);
        $lenderCategories = array_fill(0, $lenderCount, []);
        
        // Ensure every category is assigned to multiple lenders (e.g., 3 to 7 lenders per category)
        foreach ($categoryIds as $catId) {
            // Pick a random number of lenders for this category
            $numLendersForCat = rand(3, min(7, $lenderCount));
            
            // Randomly select indices of lenders
            $randomLenderIndices = (array) array_rand(array_fill(0, $lenderCount, null), $numLendersForCat);
            
            foreach ($randomLenderIndices as $lenderIndex) {
                $lenderCategories[$lenderIndex][] = $catId;
            }
        }
        
        foreach ($lenders as $index => $lender) {
            // Get unique categories for this lender
            $cats = array_unique($lenderCategories[$index] ?? []);
            $lender['product_categories'] = array_values($cats);
            
            Lender::updateOrCreate(['id' => $lender['id']], $lender);
        }

        $this->command->info('✅ 16 Lenders seeded accurately. Each category is assigned to multiple lenders.');
    }
}
