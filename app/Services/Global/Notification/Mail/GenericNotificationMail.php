<?php

namespace App\Services\Global\Notification\Mail;

use App\Mail\Contracts\SendsImmediately;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GenericNotificationMail extends Mailable implements SendsImmediately
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;

        $resolvedPartnerId = $partnerId ?? ($mailData['partner_id'] ?? null);
        if ($resolvedPartnerId) {
            $this->mailPartnerId = $resolvedPartnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];
        $subject = $this->mailData['subject'] ?? 'Notification';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.generic-notification',
            with: [
                'content' => $this->mailData['content'],
                'user' => $this->mailData['user'],
                'notificationType' => $this->mailData['notification_type'],
                'data' => $this->mailData['data'],
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
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




