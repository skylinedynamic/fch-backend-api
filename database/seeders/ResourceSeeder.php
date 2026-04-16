<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Resource;

class ResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $resources = [
            [
                'resource_type' => 'legal_registration',
                'title' => 'Business Formation Checklist',
                'subtitle' => 'Comprehensive 10-page guide',
                'icon' => 'ri-article-line',
                'file' => 'resources/seeder/business-formation.pdf',
                'status' => true,
            ],
            [
                'resource_type' => 'legal_registration',
                'title' => 'Tax Compliance Overview',
                'subtitle' => '2025 Standard Regulations',
                'icon' => 'ri-passport-line',
                'file' => 'resources/seeder/tax-compliance.pdf',
                'status' => true,
            ],
            [
                'resource_type' => 'business_services',
                'title' => 'Premium CPA Consulting',
                'subtitle' => 'Schedule a live session',
                'icon' => 'ri-briefcase-line',
                'link' => 'https://calendly.com/',
                'status' => true,
            ],
            [
                'resource_type' => 'download_assets',
                'title' => 'Executive Presentation Deck',
                'subtitle' => 'PowerPoint Template',
                'icon' => 'ri-file-ppt-2-line',
                'file' => 'resources/seeder/presentation.pdf',
                'status' => true,
            ],
            [
                'resource_type' => 'education_help',
                'title' => 'Scaling Strategies FAQ',
                'subtitle' => 'Top 50 common questions',
                'icon' => 'ri-question-answer-line',
                'file' => 'resources/seeder/scaling-faq.pdf',
                'status' => true,
            ],
            [
                'resource_type' => 'training_academy',
                'title' => 'Mastering Business Fundability',
                'subtitle' => '1.2k Views • Tutorial',
                'icon' => 'ri-video-line',
                'link' => 'https://youtube.com/watch?v=demo1',
                'status' => true,
            ],
            [
                'resource_type' => 'training_academy',
                'title' => 'Compliance Quickstart Guide',
                'subtitle' => '850 Views • Tutorial',
                'icon' => 'ri-video-line',
                'link' => 'https://youtube.com/watch?v=demo2',
                'status' => true,
            ],
        ];

        foreach ($resources as $resource) {
            Resource::updateOrCreate(['title' => $resource['title']], $resource);
        }
    }
}
