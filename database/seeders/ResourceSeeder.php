<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ResourceSeeder extends Seeder
{
    public function run()
    {
        $resources = [
            // Legal & Registration
            ['resource_type' => 'legal_registration', 'title' => 'EIN Overview',                   'subtitle' => 'Federal Identification Overview',      'icon' => 'ri-article-line'],
            ['resource_type' => 'legal_registration', 'title' => 'Entity Registration',            'subtitle' => 'Overview & Compliance Overview',       'icon' => 'ri-file-copy-2-line'],
            ['resource_type' => 'legal_registration', 'title' => 'SOS Compliance Overview',        'subtitle' => 'State Coverage Business Overview',     'icon' => 'ri-file-shield-2-line'],
            ['resource_type' => 'legal_registration', 'title' => 'Licensing & Compliance Note',    'subtitle' => 'Operational Guidance Overview',        'icon' => 'ri-file-check-line'],

            // Business Services
            ['resource_type' => 'business_services', 'title' => 'Professional Business Address',   'subtitle' => 'Business Presence Essentials',         'icon' => 'ri-building-4-line'],
            ['resource_type' => 'business_services', 'title' => '411 / Directory Listing Basics',  'subtitle' => 'Business Telecom Solutions',           'icon' => 'ri-list-check'],
            ['resource_type' => 'business_services', 'title' => 'Business Phone Setup Basics',     'subtitle' => 'Business Communication Setup',        'icon' => 'ri-phone-line'],
            ['resource_type' => 'business_services', 'title' => 'Communications & Presence',       'subtitle' => 'Business Telecom-Specific Solutions', 'icon' => 'ri-customer-service-2-line'],

            // Training Academy (12 items)
            ['resource_type' => 'training_academy', 'title' => 'Mastering Business Fundability',   'subtitle' => '1.2k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Compliance Quickstart Guide',      'subtitle' => '850 Views • Tutorial',  'icon' => 'ri-shield-check-line'],
            ['resource_type' => 'training_academy', 'title' => 'Building Tier 1 Credit Fast',      'subtitle' => '2.5k Views • Tutorial', 'icon' => 'ri-medal-line'],
            ['resource_type' => 'training_academy', 'title' => 'Advanced Cashflow Strategies',     'subtitle' => '1.1k Views • Tutorial', 'icon' => 'ri-money-dollar-circle-line'],
            ['resource_type' => 'training_academy', 'title' => 'Entity Structuring for Growth',    'subtitle' => '900 Views • Tutorial',  'icon' => 'ri-organization-chart'],
            ['resource_type' => 'training_academy', 'title' => 'Scaling Business to Tier 4',       'subtitle' => '3.2k Views • Tutorial', 'icon' => 'ri-rocket-2-line'],
            ['resource_type' => 'training_academy', 'title' => 'Understanding Credit Reports',     'subtitle' => '1.5k Views • Tutorial', 'icon' => 'ri-file-chart-line'],
            ['resource_type' => 'training_academy', 'title' => 'Navigating Funding Rounds',        'subtitle' => '720 Views • Tutorial',  'icon' => 'ri-funds-line'],
            ['resource_type' => 'training_academy', 'title' => 'Tax Strategies for Small Biz',     'subtitle' => '1.8k Views • Tutorial', 'icon' => 'ri-government-line'],
            ['resource_type' => 'training_academy', 'title' => 'Business Insurance Basics',        'subtitle' => '640 Views • Tutorial',  'icon' => 'ri-shield-user-line'],
            ['resource_type' => 'training_academy', 'title' => 'Effective Pitch Decks',            'subtitle' => '2.1k Views • Tutorial', 'icon' => 'ri-presentation-line'],
            ['resource_type' => 'training_academy', 'title' => 'Managing Remote Teams',            'subtitle' => '950 Views • Tutorial',  'icon' => 'ri-team-line'],

            // Download Assets
            ['resource_type' => 'download_assets', 'title' => 'Business Formation Checklist',      'subtitle' => 'PDF Document',          'icon' => 'ri-file-list-3-line'],
            ['resource_type' => 'download_assets', 'title' => 'Credit Application Template',       'subtitle' => 'DOCX Document',         'icon' => 'ri-file-user-line'],
            ['resource_type' => 'download_assets', 'title' => 'Vendor Worksheets',                 'subtitle' => 'XLSX Spreadsheet',      'icon' => 'ri-file-chart-line'],

            // Education & Help (12 unique items)
            ['resource_type' => 'education_help', 'title' => 'Getting Started',                    'subtitle' => 'Events & Community',              'icon' => 'ri-lightbulb-flash-line'],
            ['resource_type' => 'education_help', 'title' => 'Fundability Standards',              'subtitle' => 'Business Credit Tenstars',         'icon' => 'ri-government-line'],
            ['resource_type' => 'education_help', 'title' => 'Tier System Explained',              'subtitle' => 'Business Support and Analysis',    'icon' => 'ri-honour-line'],
            ['resource_type' => 'education_help', 'title' => 'Business Identity Basics',           'subtitle' => 'Business Resort Guide',           'icon' => 'ri-building-line'],
            ['resource_type' => 'education_help', 'title' => 'Documents & Compliance',             'subtitle' => 'Digital Asset Documentation',     'icon' => 'ri-folder-shield-2-line'],
            ['resource_type' => 'education_help', 'title' => 'Funding Readiness',                  'subtitle' => 'Business Credit Facilities',      'icon' => 'ri-bank-line'],
            ['resource_type' => 'education_help', 'title' => 'Banking & Cash Management',          'subtitle' => 'Financial Control Systems',       'icon' => 'ri-wallet-3-line'],
            ['resource_type' => 'education_help', 'title' => 'Common Denial Reasons',              'subtitle' => 'Credit Forensics & Analysis',     'icon' => 'ri-delete-bin-7-line'],
            ['resource_type' => 'education_help', 'title' => 'FAQs',                               'subtitle' => 'Frequently Asked Questions',      'icon' => 'ri-question-answer-line'],
            ['resource_type' => 'education_help', 'title' => 'Credit Glossary',                    'subtitle' => 'Funding & Credit Terms',          'icon' => 'ri-book-open-line'],
            ['resource_type' => 'education_help', 'title' => 'Tier 0 Checklist',                   'subtitle' => 'Compliance Prerequisites',        'icon' => 'ri-list-settings-line'],
            ['resource_type' => 'education_help', 'title' => 'Advanced Credit Glossary',           'subtitle' => 'Tier Prep Talk Definitions',      'icon' => 'ri-book-2-line'],

        ];

        foreach ($resources as $res) {
            \App\Models\Resource\Resource::updateOrCreate(
                [
                    'title' => $res['title'],
                    'resource_type' => $res['resource_type'],
                ],
                array_merge($res, ['status' => true])
            );
        }
    }
}
