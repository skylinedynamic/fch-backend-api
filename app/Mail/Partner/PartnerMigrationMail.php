<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerMigrationMail extends Mailable
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
        $this->dynamicData['siteName'] = $branding['siteName'] ?? config('app.name');
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $siteName = $this->dynamicData['siteName'];
        $defaultSubject = __('partner.mail.subject_account_migrated', ['company' => $siteName]);

        return new Envelope(
            subject: $this->mailData['subject'] ?? $defaultSubject,
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.trial.partner_migration',
            with: [
                'user' => $user,
                'partner_id' => $this->mailData['partner_id'],
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? 0,
                'enrollmentType' => $this->mailData['enrollment_type'] ?? 'Paid Subscription',

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
