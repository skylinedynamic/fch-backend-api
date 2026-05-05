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
                'page_name' => 'Home',
                'path' => '/',
                'meta_title' => 'Four Corner Funding - Business Funding Solutions',
                'meta_description' => 'Fast and reliable business funding solutions. Get prequalified in minutes for working capital, equipment financing, and more.',
            ],
            [
                'page_name' => 'Funding Options',
                'path' => '/funding-options',
                'meta_title' => 'Funding Options | Four Corner Funding',
                'meta_description' => 'Explore our wide range of business funding options including MCA, Term Loans, and Equipment Financing.',
            ],
            [
                'page_name' => 'Credit Builder',
                'path' => '/credit-builder',
                'meta_title' => 'Business Credit Builder Program | Four Corner Funding',
                'meta_description' => 'Build and improve your business credit profile with our expert-led credit builder program.',
            ],
            [
                'page_name' => 'Partner Program',
                'path' => '/partner-program',
                'meta_title' => 'Partner with Us | Four Corner Funding',
                'meta_description' => 'Join our white-label partner program and offer industry-leading funding solutions to your clients.',
            ],
            [
                'page_name' => 'FAQ',
                'path' => '/faq',
                'meta_title' => 'Frequently Asked Questions | Four Corner Funding',
                'meta_description' => 'Find answers to common questions about our funding process, requirements, and programs.',
            ],
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
            // NOTE: Individual blog post SEO is handled dynamically via each
            // blog post's own meta_title / meta_description fields (getBlogPostMetadata).
            // No seo_settings rows are needed per blog post slug.
            // [
            //     'page_name' => 'Terms of Service',
            //     'path' => '/terms',
            //     'meta_title' => 'Terms of Service | Four Corner Funding',
            //     'meta_description' => 'Read our terms and conditions for using Four Corner Funding services.',
            // ],
            // [
            //     'page_name' => 'Privacy Policy',
            //     'path' => '/privacy-policy',
            //     'meta_title' => 'Privacy Policy | Four Corner Funding',
            //     'meta_description' => 'How we handle and protect your personal and business information.',
            // ],
            // [
            //     'page_name' => 'Disclosure',
            //     'path' => '/disclosure',
            //     'meta_title' => 'Legal Disclosures | Four Corner Funding',
            //     'meta_description' => 'Important legal disclosures and transparency information for our clients.',
            // ],
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
            SeoSetting::updateOrCreate(
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
