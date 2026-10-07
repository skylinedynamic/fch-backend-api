<?php

namespace App\Mail\Borrower;

use App\Mail\Contracts\SendsImmediately;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerAccountCreatedMail extends Mailable implements SendsImmediately
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
            : __('borrower.mail.subject_account_created', ['company' => $this->dynamicData['siteName']]);
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
        // No fallback passed here on purpose: when getBorrowerDomain() returns
        // null (a non-host, non-Certified partner with no borrower_domain — see
        // its docblock), the partner has no domain a borrower can actually log
        // in through, so $loginUrl comes back empty and the template omits the
        // button rather than link to a login the isolation check would reject.
        $loginUrl = concatUrl(getBorrowerDomain($this->mailPartnerId), 'login');

        return new Content(
            view: 'emails.borrower.account_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
                'siteName' => $siteName,
                'logoUrl' => $logoUrl,
                'business_info' => [
                    'company_name' => $this->mailData['business_info']['company_name'] ?? $siteName,
                    'email' => $this->mailData['business_info']['email'] ?? '',
                    'phone_number' => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1' => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? null,
                'is_update' => $this->mailData['is_update'] ?? false,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
