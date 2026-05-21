<?php

namespace App\Mail\Traits;

use App;
use App\Models\Common\AppConfiguration;
use Illuminate\Support\Facades\Config;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

trait MailConfig
{
    protected ?int $mailPartnerId = null;

    /**
     * Set the partner ID for dynamic SMTP configuration.
     */
    public function setMailPartnerId(): self
    {
        $partner = getConfigPartner();
        $this->mailPartnerId = $partner->id;
        return $this;
    }

    /**
     * Apply dynamic mail configuration before sending.
     * This handles SMTP/SES transport switching without branding.
     */
    public function send($mailer)
    {
        $partnerId = $this->mailPartnerId;

        $service = app(EmailConfigurationService::class);
        $config = $service->getConfigurations($partnerId)->first();

        $siteName = AppConfiguration::get('site_name', '', AppConfiguration::MODULE_BRANDING, $partnerId);

        // Store original config to restore later
        $originalConfig = [
            'mail.default' => config('mail.default'),
            'mail.from.address' => config('mail.from.address'),
            'mail.from.name' => config('mail.from.name'),
            'mail.bcc' => config('mail.bcc'),
            'services.ses.key' => config('services.ses.key'),
            'services.ses.secret' => config('services.ses.secret'),
            'services.ses.region' => config('services.ses.region'),
        ];

        // Apply dynamic SMTP/SES settings
        if ($config && !empty($config->enable_custom_configuration)) {
            $driver = ($config->email_provider ?? 'smtp') === 'aws_ses' ? 'ses' : 'smtp';

            \Illuminate\Support\Facades\Log::info("Applying custom mail config for Partner ID: {$partnerId}", [
                'driver' => $driver,
                'from' => $config->from_address ?? null,
                'name' => $siteName,
                'host' => $config->smtp_host ?? ($config->aws_region ?? 'N/A'),
                'username' => $config->smtp_username ?? null,
                'password' => $config->smtp_password ?? null,
                'port' => $config->smtp_port ?? null,
                'encryption' => $config->smtp_encryption ?? null,
                'aws_access_key' => $config->aws_access_key ?? null,
                'aws_secret_key' => $config->aws_secret_key ?? null,
                'aws_region' => $config->aws_region ?? null,
            ]);

            Config::set('mail.default', $driver);

            if ($driver == 'smtp') {
                Config::set('mail.mailers.smtp.host', $config->smtp_host ?? null);
                Config::set('mail.mailers.smtp.port', $config->smtp_port ?? null);
                Config::set('mail.mailers.smtp.username', $config->smtp_username ?? null);
                Config::set('mail.mailers.smtp.password', $config->smtp_password ?? null);
                Config::set('mail.mailers.smtp.encryption', $config->smtp_encryption ?? null);

            } else {
                Config::set('services.ses.key', $config->aws_access_key ?? null);
                Config::set('services.ses.secret', $config->aws_secret_key ?? null);
                Config::set('services.ses.region', $config->aws_region ?? null);
            }

            Config::set('mail.from.address', $config->from_address ?? null);
            Config::set('mail.from.name', $siteName);
            Config::set('mail.bcc', $config->bcc_emails ?? null);

            // MANUALLY SET SENDER ON MAILABLE TO BYPASS CACHED ENVELOPE RESOLUTION
            $this->from($config->from_address ?? config('mail.from.address'), $siteName);

            // Purge resolved mailers to force re-resolution with new config
            app('mail.manager')->forgetMailers();

            // Re-resolve the mailer instance with the new configuration
            $mailer = app('mail.manager')->mailer($driver);
        }

        try {
            return parent::send($mailer);
        } finally {
            // Restore original configurations
            foreach ($originalConfig as $key => $value) {
                Config::set($key, $value);
            }
            // Purge resolved mailers to force re-resolution with restored default config
            app('mail.manager')->forgetMailers();
        }
    }
}
