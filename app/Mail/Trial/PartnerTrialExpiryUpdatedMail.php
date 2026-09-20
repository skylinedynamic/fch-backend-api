<?php

namespace App\Mail\Trial;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerTrialExpiryUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public array $mailData;
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
        $defaultSubject = __('partner.mail.subject_trial_expiry_updated', ['company' => $siteName]);

        return new Envelope(
            subject: $this->mailData['subject'] ?? $defaultSubject,
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.trial.partner_trial_expiry_updated',
            with: [
                'user' => $user,
                'partner_id' => $this->mailData['partner_id'],
                'partner' => $this->mailData['partner'] ?? null,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'trialEndDate' => $this->mailData['trial_end_date'] ?? null,
                'oldTrialEndDate' => $this->mailData['old_trial_end_date'] ?? null,

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
