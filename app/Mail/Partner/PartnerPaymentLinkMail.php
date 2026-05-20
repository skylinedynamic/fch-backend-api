<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerPaymentLinkMail extends Mailable
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->setMailPartnerId();
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: $this->mailData['subject'] ?? "Complete Your Partner Enrollment",
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.partner.account_created',
            with: [
                'user' => $user,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? 0,
                'enrollmentType' => $this->mailData['enrollment_type'] ?? 'One-Time Payment',
                'plainPassword' => $this->mailData['plain_password'] ?? null,

                'business_info' => [
                    'company_name' => $this->mailData['business_info']['company_name'] ?? '',
                    'email' => $this->mailData['business_info']['email'] ?? '',
                    'phone_number' => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1' => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
