<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Partner\Partner;
use App\Models\Common\AppConfiguration;

class AppConfigurationsSeeder extends Seeder
{
    public function run(): void
    {
        $entityType = Partner::class;
        $entityId = 1;

        $settings = [
            'site_name' => 'Four Corner Funding',
            'site_slogan' => '',
            'header_logo' => 'site-settings/black-logo.png',
            'footer_logo' => 'site-settings/logo-white.png',
            'partner_logo' => 'site-settings/fch-logo-wide.png',
            'favicon' => 'site-settings/Favicon.png',
            'address' => "Four Corner Holdings, LLC 3750 Gunn Highway, Suite 306 Tampa, FL. 33618",
            'phone_number' => '(813) 391-3333',
            'email' => 'Info@fourcornerfunding.com',

            // ✅ keep as ARRAY (not json_encode here)
            'social_links' => [
                ['url' => 'https://www.facebook.com', 'icon' => 'ri-facebook-fill', 'platform' => 'Facebook'],
                ['url' => 'https://bd.linkedin.com', 'icon' => 'ri-linkedin-fill', 'platform' => 'LinkedIn'],
                ['url' => 'https://www.youtube.com', 'icon' => 'ri-youtube-fill', 'platform' => 'YouTube'],
                ['url' => 'https://x.com/?lang=en', 'icon' => 'ri-twitter-x-fill', 'platform' => 'Twitter (X)'],
            ],

            'map_iframe' => '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3787.552684995253!2d-82.5072806!3d28.0367411!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x88c2c1393912d155%3A0x41d1edef6ef2477b!2s3750%20Gunn%20Hwy%20suite%20306%2C%20Tampa%2C%20FL%2033618%2C%20USA!5e1!3m2!1sen!2sbd!4v1777289734976!5m2!1sen!2sbd" width="100%" height="400" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>',

            'google_measurement_id' => 'G-JNZ52ZKJ2W',
            'recaptcha_site_key' => '6LejTe8rAAAAADo3ijCRWqJN9rIfS8g3cVueUXBx',
            'recaptcha_secret_key' => '6LejTe8rAAAAABavYv_MvOWLDeYkGJXtm9V9p3KT',
        ];

        foreach ($settings as $key => $value) {

            AppConfiguration::updateOrCreate(
                [
                    'key' => $key,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ],
                [
                    // ✅ FIX: handle array properly
                    'value' => is_array($value)
                        ? json_encode($value)
                        : (string) $value,
                ]
            );
        }
    }
}