<?php

namespace Database\Seeders;

use App\Models\Setting\MailConfiguration;
use Illuminate\Database\Seeder;

/**
 * Local mail settings: the platform fallback and the host's own SMTP, both
 * pointed at the Mailtrap sandbox and marked verified, so a fresh database
 * sends mail without anyone verifying first.
 */
class EmailConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $hostId = 1;

        $smtp = [
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'sajib123arafat@gmail.com',
            'bcc_emails' => 'log@fourcornerfunding.com',
            'credentials' => [
                'smtp_host' => 'sandbox.smtp.mailtrap.io',
                'smtp_port' => 2525,
                'smtp_encryption' => 'tls',
                'smtp_username' => 'ec3b2a141588f8',
                'smtp_password' => 'e5d9ae5ad4cd91',
            ],
        ];

        $fallback = MailConfiguration::fallback()->where('provider', MailConfiguration::PROVIDER_SMTP)->first()
            ?? new MailConfiguration(['partner_id' => null]);
        $this->saveVerified($fallback->fill($smtp + ['is_default' => true]));

        $own = MailConfiguration::forPartner($hostId)->where('provider', MailConfiguration::PROVIDER_SMTP)->first()
            ?? new MailConfiguration(['partner_id' => $hostId]);
        $this->saveVerified($own->fill($smtp + ['is_default' => true]));
    }

    private function saveVerified(MailConfiguration $config): void
    {
        $config->forceFill([
            'verified_at' => now(),
            'verified_fingerprint' => $config->fingerprint(),
            'verified_to' => 'seeded',
        ])->save();
    }
}
