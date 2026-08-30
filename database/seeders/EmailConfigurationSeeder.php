<?php

namespace Database\Seeders;

use App\Models\Setting\EmailConfiguration;
use Illuminate\Database\Seeder;

class EmailConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partnerId = 1;
        $configs = [
            'enable_custom_configuration' => '1',
            'email_provider' => 'smtp',
            'site_name' => 'Four Corner Funding',

            // SMTP Settings
            'smtp_driver' => 'smtp',
            'smtp_host' => 'sandbox.smtp.mailtrap.io',
            'smtp_port' => '2525',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'ec3b2a141588f8',
            'smtp_password' => 'e5d9ae5ad4cd91',

            // AWS SES Settings (Placeholders)
            'aws_access_key' => 'your_aws_access_key',
            'aws_secret_key' => 'your_aws_secret_key',
            'aws_region' => 'us-east-1',

            'from_address' => 'sajib123arafat@gmail.com',
            'bcc_emails' => 'log@fourcornerfunding.com',
            'is_active' => '1',
            'is_fresh_email' => '1',
        ];

        foreach ($configs as $key => $value) {
            EmailConfiguration::updateOrCreate(
                [
                    'partner_id' => $partnerId,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }
    }
}
