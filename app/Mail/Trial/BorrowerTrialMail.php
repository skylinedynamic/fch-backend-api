<?php

namespace App\Mail\Trial;

use App\Mail\Contracts\SendsImmediately;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerTrialMail extends Mailable implements SendsImmediately
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
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
            subject: $this->mailData['subject'] ?? __('borrower.mail.subject_account_created_trial', ['company' => $this->dynamicData['siteName']]),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];
        $loginUrl = concatUrl(getBorrowerDomain($this->mailPartnerId), 'login', config('app.frontend_borrower_url'));

        $rawEndDate = $this->mailData['trial_end_date'] ?? null;
        $trialEndDate = $rawEndDate ? normalizeDate($rawEndDate) : null;

        return new Content(
            view: 'emails.trial.borrower_account_created',
            with: [
                'user' => $user,
                'partner_id' => $this->mailPartnerId,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
                'trialEndDate' => $trialEndDate,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
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
