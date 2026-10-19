<?php

namespace App\Mail\Setting;

use App\Mail\Contracts\SendsImmediately;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use App\Models\User\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The code that verifies a mail configuration. Sent through exactly the
 * configuration being verified - getting it at all is the proof - and never
 * queued, so the settings screen learns at once whether it went out.
 */
class MailConfigurationVerificationMail extends Mailable implements SendsImmediately
{
    use MailableBranding, MailConfig;

    public function __construct(
        public readonly string $code,
        private readonly MailConfiguration $configuration,
        private readonly User $user,
    ) {
        $this->mailPartnerId = $configuration->partner_id ?? Partner::where('is_host', true)->value('id');
        $this->useMailSettings($configuration->toMailSettings());
    }

    public function configurationId(): int
    {
        return $this->configuration->id;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail_config.verification_mail.subject'));
    }

    public function content(): Content
    {
        $branding = $this->getBrandingData();
        $provider = MailConfiguration::providers()[$this->configuration->provider]['label'] ?? $this->configuration->provider;
        $minutes = (int) config('mail_configuration.verification.ttl_minutes');

        return new Content(
            view: 'emails.generic-notification',
            with: [
                'content' => [
                    'subject' => __('mail_config.verification_mail.subject'),
                    'greeting' => __('mail_config.verification_mail.greeting', ['name' => $this->user->name]),
                    'lines' => [
                        __('mail_config.verification_mail.line', ['provider' => $provider, 'address' => $this->configuration->from_address]),
                        __('mail_config.verification_mail.expiry', ['minutes' => $minutes]),
                    ],
                    'action' => null,
                ],
                'user' => $this->user,
                'notificationType' => 'mail_configuration_verification',
                'data' => ['code' => $this->code],
                'siteName' => $branding['siteName'],
                'logoUrl' => $branding['logoUrl'],
            ],
        );
    }
}
