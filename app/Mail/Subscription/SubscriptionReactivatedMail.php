<?php

namespace App\Mail\Subscription;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * BUG-069: a cancelled subscription is active again - resumed from the
 * portal, or reactivated by paying an invoice it still had open. Sent to the
 * subscriber.
 */
class SubscriptionReactivatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param array{user_name?: ?string, next_billing_date?: ?string, portal_url?: ?string} $data
     */
    public function __construct(public array $data, ?int $partnerId = null)
    {
        // Also sent from a payment webhook or the cron: no request to resolve
        // the sender from, so the caller names it (see AutoChargeStoppedMail).
        if ($partnerId) {
            $this->mailPartnerId = $partnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __('subscription.resume.mail_subject', ['site' => $this->dynamicData['siteName']]),
        );
    }

    public function content(): Content
    {
        $userName = $this->data['user_name'] ?? __('payment.valued_customer');

        return new Content(
            view: 'emails.subscription.reactivated',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'first_name' => explode(' ', trim($userName))[0],
                'next_billing_date' => $this->data['next_billing_date'] ?? null,
                'portal_url' => $this->data['portal_url'] ?? null,
                'company_name' => $this->dynamicData['siteName'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
