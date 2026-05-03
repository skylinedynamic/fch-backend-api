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
                'page_name' => 'Terms of Service',
                'path' => '/terms',
                'meta_title' => 'Terms of Service | Four Corner Funding',
                'meta_description' => 'Read our terms and conditions for using Four Corner Funding services.',
            ],
            [
                'page_name' => 'Privacy Policy',
                'path' => '/privacy-policy',
                'meta_title' => 'Privacy Policy | Four Corner Funding',
                'meta_description' => 'How we handle and protect your personal and business information.',
            ],
            [
                'page_name' => 'Disclosure',
                'path' => '/disclosure',
                'meta_title' => 'Legal Disclosures | Four Corner Funding',
                'meta_description' => 'Important legal disclosures and transparency information for our clients.',
            ],
            [
                'page_name' => 'About Us',
                'path' => '/about-us',
                'meta_title' => 'About Four Corner Funding | Our Mission',
                'meta_description' => 'Learn about Four Corner Funding, our mission, and our commitment to helping small businesses grow.',
            ],
            [
                'page_name' => 'Career',
                'path' => '/career',
                'meta_title' => 'Careers | Join the Four Corner Funding Team',
                'meta_description' => 'Join a dynamic team dedicated to revolutionizing the business funding industry.',
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
