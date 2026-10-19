<?php

namespace App\Services\Global\Notification\Mail;

use App\Mail\Contracts\SendsImmediately;
use App\Models\Partner\Partner;
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

        $resolvedPartnerId = $partnerId
            ?? ($mailData['partner_id'] ?? null)
            ?? self::recipientPartnerId($mailData['user'] ?? null);
        if ($resolvedPartnerId) {
            $this->mailPartnerId = $resolvedPartnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    /**
     * The partner whose mail this is, from whoever it is addressed to - its
     * settings and its name. A password reset is sent signed out, so the
     * request says nothing about the partner (on localhost, nothing at all);
     * the recipient always does. Host and white-label partners are their own;
     * any other trades under the host's brand, as everywhere else.
     */
    private static function recipientPartnerId(mixed $user): ?int
    {
        $partnerId = $user->partner_id ?? null;
        if (!$partnerId) {
            return null;
        }

        $partner = Partner::find($partnerId);
        if (!$partner) {
            return null;
        }

        return ($partner->is_host || $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)
            ? $partner->id
            : Partner::where('is_host', true)->value('id');
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




