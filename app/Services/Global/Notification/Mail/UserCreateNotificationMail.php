<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

class UserCreateNotificationMail extends Mailable
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

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $defaultSubject = 'Welcome to ' . $branding['siteName'];
        $subject = $this->mailData['subject'] ?? $defaultSubject;

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Determine login URL dynamically
        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN,
            \App\Models\User\User::PARTNER => concatUrl(getPartnerDomain($this->mailPartnerId), 'login', config('app.frontend_partner_url')),
            \App\Models\User\User::BORROWER => concatUrl(getBorrowerDomain($this->mailPartnerId), 'login', config('app.frontend_borrower_url')),
            default => concatUrl(getPartnerDomain($this->mailPartnerId), 'login', config('app.frontend_partner_url')),
        };

        return new Content(
            view: 'emails.users_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'business_info' => [
                    'company_name' => $this->mailData['business_info']['company_name'] ?? ($this->dynamicData['siteName'] ?? config('app.name')),
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
