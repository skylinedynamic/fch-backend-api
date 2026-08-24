<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountCreateNotificationMail extends Mailable
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $resolvedPartnerId = $partnerId
            ?? ($mailData['partner_id'] ?? null)
            ?? ($mailData['user']?->partner_id ?? null);

        $this->setMailPartnerId($resolvedPartnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData($this->mailPartnerId);
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];
        $defaultSubject = !empty($this->mailData['is_update'])
            ? __('common.email_changed_subject')
            : "Welcome to " . $branding['siteName'];
        $subject = $this->mailData['subject'] ?? $defaultSubject;

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $branding = $this->getBrandingData($this->mailPartnerId);
        $siteName = $branding['siteName'];
        $logoUrl = $branding['logoUrl'];

        $user = $this->mailData['user'];
        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN,
            \App\Models\User\User::PARTNER => concatUrl(getPartnerDomain($this->mailPartnerId), 'login', config('app.frontend_partner_url')),
            \App\Models\User\User::BORROWER => concatUrl(getBorrowerDomain($this->mailPartnerId), 'login', config('app.frontend_borrower_url')),
            default => concatUrl(getPartnerDomain($this->mailPartnerId), 'login', config('app.frontend_partner_url')),
        };

        return new Content(
            view: 'emails.account_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
                'siteName' => $siteName,
                'logoUrl' => $logoUrl,
                'business_info' => [
                    'company_name' => $this->mailData['business_info']['company_name'] ?? ($siteName ?? config('app.name')),
                    'email' => $this->mailData['business_info']['email'] ?? '',
                    'phone_number' => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1' => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
