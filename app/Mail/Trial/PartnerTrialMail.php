<?php

namespace App\Mail\Trial;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerTrialMail extends Mailable
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

        return new Envelope(
            subject: $this->mailData['subject'] ?? "Welcome to Your Partner Portal - Free Trial",
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.trial.account_created',
            with: [
                'user' => $user,
                'partner_id' => $this->mailData['partner_id'],
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'trialEndDate' => $this->mailData['trial_end_date'] ?? null,
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
