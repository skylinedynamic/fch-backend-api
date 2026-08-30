<?php

namespace Database\Seeders;

use App\Models\CMS\SeoSetting;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Seeder;

class SeoSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $pages = [
            [
                'page_name' => 'SBA Programs',
                'path' => '/sba-programs',
                'meta_title' => 'SBA Loan Programs | Four Corner Funding',
                'meta_description' => 'Explore SBA loan programs designed to help small businesses access government-backed funding with flexible terms.',
            ],
            [
                'page_name' => 'Business Acquisition',
                'path' => '/business-acquisition',
                'meta_title' => 'Business Acquisition Financing | Four Corner Funding',
                'meta_description' => 'Get the funding you need to acquire or expand a business with our tailored business acquisition financing solutions.',
            ],
            [
                'page_name' => 'Working Capital & Growth',
                'path' => '/working-capital-and-growth',
                'meta_title' => 'Working Capital & Growth Funding | Four Corner Funding',
                'meta_description' => 'Access working capital and growth financing to keep your business running smoothly and scale faster.',
            ],
            [
                'page_name' => 'Real Estate',
                'path' => '/real-estate',
                'meta_title' => 'Real Estate Financing | Four Corner Funding',
                'meta_description' => 'Secure real estate funding for commercial properties, investment purchases, and refinancing with Four Corner Funding.',
            ],
            [
                'page_name' => 'Asset & Equipment',
                'path' => '/asset-and-equipment',
                'meta_title' => 'Asset & Equipment Financing | Four Corner Funding',
                'meta_description' => 'Finance the equipment and assets your business needs with flexible, affordable equipment financing solutions.',
            ],
            [
                'page_name' => 'Receivables & Cash Flow',
                'path' => '/receivables-and-cash-flow',
                'meta_title' => 'Receivables & Cash Flow Financing | Four Corner Funding',
                'meta_description' => 'Unlock the cash tied up in your receivables with invoice financing and cash flow solutions from Four Corner Funding.',
            ],
            [
                'page_name' => 'Bridge & Short Term',
                'path' => '/bridge-short-term',
                'meta_title' => 'Bridge & Short-Term Loans | Four Corner Funding',
                'meta_description' => 'Access fast bridge and short-term financing to cover immediate capital needs while waiting for long-term funding.',
            ],

            [
                'page_name' => 'Contact Us',
                'path' => '/contact',
                'meta_title' => 'Contact Four Corner Funding',
                'meta_description' => 'Get in touch with Four Corner Funding for business funding and credit building inquiries.',
            ],
            [
                'page_name' => 'Pre-Qualification',
                'path' => '/pre-qualification',
                'meta_title' => 'Pre-Qualification | Four Corner Funding',
                'meta_description' => 'Get pre-qualified for business funding in minutes. Fast, easy, and no impact to your credit score.',
            ],
            

        ];

        foreach ($pages as $page) {
            SeoSetting::firstOrCreate(
                ['page_name' => $page['page_name'], 'partner_id' => 1],
                [
                    'path' => $page['path'],
                    'meta_title' => $page['meta_title'],
                    'meta_description' => $page['meta_description'],
                    'keywords' => 'business funding, capital, credit builder, lending',
                    'created_by' => 1,
                    'updated_by' => 1,
                ]
            );
        }
    }
}
