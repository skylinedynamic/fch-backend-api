<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerAccountCreatedMail extends Mailable
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
                ?? 'Welcome to ' . ($this->mailData['business_info']['company_name'] ?? 'Our Company'),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Determine login URL dynamically - specific for borrower
        $loginUrl = config('app.frontend_borrower_url') . '/login';

        // ✅ Handle business logo properly
        $businessLogo = $this->mailData['business_info']['company_logo'] ?? 'logo.png';

        // If full URL was provided, convert to filename
        if (str_starts_with($businessLogo, 'http')) {
            // Try to extract the file name (for example: logo.png)
            $fileName = basename(parse_url($businessLogo, PHP_URL_PATH));
            $localPath = public_path($fileName);
        } else {
            // If it's already a relative path or stored in /public
            $localPath = public_path($businessLogo);
        }

        // ✅ Prepare URLs and paths
        $businessLogoUrl = asset($businessLogo);
        $businessLogoPath = file_exists($localPath) ? $localPath : public_path('logo.png');

        return new Content(
            view: 'emails.borrower.account_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,

                'business_info' => [
                    'company_name'   => $this->mailData['business_info']['company_name'] ?? '',
                    'email'  => $this->mailData['business_info']['email'] ?? '',
                    'phone_number'  => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1'  => $this->mailData['business_info']['address_line_1'] ?? '',
                    'business_logo_url'  => $businessLogoUrl,  // For production (external)
                    'business_logo_path' => $businessLogoPath, // For local embed
                ],
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? null,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
