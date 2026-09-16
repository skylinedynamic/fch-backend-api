<?php

namespace App\Mail;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the operators when a server resource crosses its threshold.
 *
 * Addressed to CRON_ERROR_NOTIFY_EMAILS - the same list the cron failure alert
 * uses - and sent with the HOST partner's mail configuration, pinned in the
 * constructor. This is infrastructure, not tenant correspondence: it belongs to
 * whoever runs the platform, so it must not go out under some partner's mail
 * server just because their request happened to trigger it.
 *
 * MailConfig is what applies those credentials. Mail::raw(), which this replaces,
 * bypasses the trait entirely - and since credentials moved out of config/mail.php
 * into `email_configurations`, a raw send now has no mail server at all.
 */
class SystemHealthAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param  array<int, array{metric: string, usage: float, threshold: float}>  $alerts
     *         Only the resources that breached, each with the reading that breached it.
     * @param  array<string, float>  $metrics  Every reading, so the mail shows the
     *         healthy resources too - "disk at 96%, CPU at 12%" is a different
     *         problem from everything being on fire.
     */
    public function __construct(
        public array $alerts,
        public array $metrics = []
    ) {
        // The platform's own mailbox, never the request's tenant.
        $this->mailPartnerId = Partner::where('is_host', 1)->value('id');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        // The breached resources go in the subject: an operator triaging a full
        // inbox needs to know whether it is the disk or the CPU without opening
        // anything.
        $names = implode(', ', array_column($this->alerts, 'metric'));

        return new Envelope(
            subject: sprintf(
                '[%s] System Health Alert: %s',
                $this->dynamicData['siteName'],
                $names !== '' ? $names : 'threshold exceeded'
            ),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.system_health_alert',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'alerts' => $this->alerts,
                'metrics' => $this->metrics,
                'host' => gethostname() ?: 'unknown',
                'environment' => app()->environment(),
                'timestamp' => now()->toDayDateTimeString(),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
