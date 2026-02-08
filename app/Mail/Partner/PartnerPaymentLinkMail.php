<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerPaymentLinkMail extends Mailable
{
    protected array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailData['subject']
                ?? 'Complete Your Partner Enrollment - ' . ($this->mailData['business_info']['company_name'] ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Handle business logo properly
        $businessLogo = $this->mailData['business_info']['company_logo'] ?? 'logo.png';

        // If full URL was provided, convert to filename
        if (str_starts_with($businessLogo, 'http')) {
            $fileName = basename(parse_url($businessLogo, PHP_URL_PATH));
            $localPath = public_path($fileName);
        } else {
            $localPath = public_path($businessLogo);
        }

        // ✅ Prepare URLs and paths
        $businessLogoUrl = asset($businessLogo);
        $businessLogoPath = file_exists($localPath) ? $localPath : public_path('logo.png');

        return new Content(
            view: 'emails.partner.account_created',
            with: [
                'user' => $user,
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? 0,
                'enrollmentType' => $this->mailData['enrollment_type'] ?? 'One-Time Payment',
                'plainPassword' => $this->mailData['plain_password'] ?? null,

                'business_info' => [
                    'company_name'   => $this->mailData['business_info']['company_name'] ?? '',
                    'email'  => $this->mailData['business_info']['email'] ?? '',
                    'phone_number'  => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1'  => $this->mailData['business_info']['address_line_1'] ?? '',
                    'business_logo_url'  => $businessLogoUrl,
                    'business_logo_path' => $businessLogoPath,
                ],
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
