<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerAccountUpdateMail extends Mailable
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $hostPartnerId = null)
    {
        $this->mailData = $mailData;
        if ($hostPartnerId) {
            $this->mailPartnerId = $hostPartnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];
        $defaultSubject = __('common.email_changed_subject');
        return new Envelope(
            subject: $this->mailData['subject'] ?? $defaultSubject,
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.partner.mail_updated',
            with: [
                'partner_id' => $this->mailData['partner_id'],
                'user' => $user,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'business_info' => [
                    'company_name' => $this->mailData['business_info']['company_name'] ?? '',
                    'email' => $this->mailData['business_info']['email'] ?? '',
                    'phone_number' => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1' => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
                'is_update' => $this->mailData['is_update'] ?? false,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
