<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Resource;

class ResourceSeeder extends Seeder
{
    public function run()
    {
        $resources = [
            // Legal & Registration
            ['resource_type' => 'legal_registration', 'title' => 'EIN Overview', 'subtitle' => 'Federal Identification Overview', 'icon' => 'ri-article-line'],
            ['resource_type' => 'legal_registration', 'title' => 'Entity Registration', 'subtitle' => 'Overview & Compliance Overview', 'icon' => 'ri-file-copy-2-line'],
            ['resource_type' => 'legal_registration', 'title' => 'SOS Compliance Overview', 'subtitle' => 'Overviews State Coverage Business', 'icon' => 'ri-file-shield-2-line'],
            ['resource_type' => 'legal_registration', 'title' => 'Licensing & Compliance Note', 'subtitle' => 'Operational Guidance Overview', 'icon' => 'ri-file-check-line'],

            // Business Services
            ['resource_type' => 'business_services', 'title' => 'Professional Business Address', 'subtitle' => 'Business Presence Essentials', 'icon' => 'ri-phone-circle-line'],
            ['resource_type' => 'business_services', 'title' => '411 / Directory Listing Basics', 'subtitle' => 'Business Telcom Solutions', 'icon' => 'ri-list-check'],
            ['resource_type' => 'business_services', 'title' => 'Business Phone Setup Basics', 'subtitle' => 'ri-terminal-window-line', 'icon' => 'ri-terminal-window-line'],
            ['resource_type' => 'business_services', 'title' => 'Communications & Presence Standards', 'subtitle' => 'Business Telecom-Specific Solutions', 'icon' => 'ri-chat-1-line'],

            // Training Academy (12 items)
            ['resource_type' => 'training_academy', 'title' => 'Mastering Business Fundability', 'subtitle' => '1.2k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Compliance Quickstart Guide', 'subtitle' => '850 Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Building Tier 1 Credit Fast', 'subtitle' => '2.5k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Advanced Cashflow Strategies', 'subtitle' => '1.1k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Entity Structuring for Growth', 'subtitle' => '900 Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Scaling Business to Tier 4', 'subtitle' => '3.2k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Understanding Credit Reports', 'subtitle' => '1.5k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Navigating Funding Rounds', 'subtitle' => '720 Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Tax Strategies for Small Biz', 'subtitle' => '1.8k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Business Insurance Basics', 'subtitle' => '640 Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Effective Pitch Decks', 'subtitle' => '2.1k Views • Tutorial', 'icon' => 'ri-video-line'],
            ['resource_type' => 'training_academy', 'title' => 'Managing Remote Teams', 'subtitle' => '950 Views • Tutorial', 'icon' => 'ri-video-line'],
            
            // Download Assets
            ['resource_type' => 'download_assets', 'title' => 'Business Formation Checklist', 'subtitle' => 'PDF Document', 'icon' => 'ri-file-list-3-line'],
            ['resource_type' => 'download_assets', 'title' => 'Credit Application Template', 'subtitle' => 'DOCX Document', 'icon' => 'ri-file-user-line'],
            ['resource_type' => 'download_assets', 'title' => 'Vendor Worksheets', 'subtitle' => 'XLSX Spreadsheet', 'icon' => 'ri-file-chart-line'],

             // Education & Help (12 items)
            ['resource_type' => 'education_help', 'title' => 'Getting Started', 'subtitle' => 'Events Community', 'icon' => 'ri-lightbulb-flash-line'],
            ['resource_type' => 'education_help', 'title' => 'Fundability Standards', 'subtitle' => 'Business Credit Tenstars', 'icon' => 'ri-government-line'],
            ['resource_type' => 'education_help', 'title' => 'Tier System Explained', 'subtitle' => 'Business Support and Analysis', 'icon' => 'ri-honour-line'],
            ['resource_type' => 'education_help', 'title' => 'Tier System Explained', 'subtitle' => 'Business Resort Guide', 'icon' => 'ri-building-line'],
            ['resource_type' => 'education_help', 'title' => 'Documents & Compliance', 'subtitle' => 'Digital Asset Documentation', 'icon' => 'ri-folder-shield-2-line'],
            ['resource_type' => 'education_help', 'title' => 'Funding Readiness', 'subtitle' => 'Business Credit Facilities', 'icon' => 'ri-bank-line'],
            ['resource_type' => 'education_help', 'title' => 'Banking & Cash Management', 'subtitle' => 'Financial Control Systems', 'icon' => 'ri-wallet-3-line'],
            ['resource_type' => 'education_help', 'title' => 'Common Denial Reasons', 'subtitle' => 'Glossary & Forensics', 'icon' => 'ri-delete-bin-7-line'],
            ['resource_type' => 'education_help', 'title' => 'FAQs', 'subtitle' => 'Handle 1a Animation Graphics', 'icon' => 'ri-question-answer-line'],
            ['resource_type' => 'education_help', 'title' => 'Glossary', 'subtitle' => 'Funding & Credit Terms', 'icon' => 'ri-book-open-line'],
            ['resource_type' => 'education_help', 'title' => 'Tier 0 Checklist', 'subtitle' => 'Compliance Prerequisites', 'icon' => 'ri-list-settings-line'],
            ['resource_type' => 'education_help', 'title' => 'Glossary', 'subtitle' => 'Tier Prep Talk Freebies', 'icon' => 'ri-book-2-line'],

        ];

        foreach ($resources as $res) {
            Resource::updateOrCreate(
                [
                    'title' => $res['title'],
                    'resource_type' => $res['resource_type'],
                    'subtitle' => $res['subtitle']
                ],
                array_merge($res, ['status' => true])
            );
        }
    }
}
