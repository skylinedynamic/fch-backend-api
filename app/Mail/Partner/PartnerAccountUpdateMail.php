<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerAccountUpdateMail extends Mailable
{
    use MailableBranding;

    protected array $mailData;

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->resolveMailConfig($partnerId);
    }

    public function envelope(): Envelope
    {
        $siteName = config('app.name') ?? 'Four Corner Funding';
        if ($this->resolvedMailConfig && $this->resolvedMailConfig->site_name) {
            $siteName = $this->resolvedMailConfig->site_name;
        }

        return new Envelope(
            subject: $this->mailData['subject']
                ?? "Welcome to {$siteName} - Partner Account Updated",
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];
        $businessLogo = $this->mailData['business_info']['company_logo'] ?? 'logo.png';

        if (str_starts_with($businessLogo, 'http')) {
            $fileName = basename(parse_url($businessLogo, PHP_URL_PATH));
            $localPath = public_path($fileName);
        } else {
            $localPath = public_path($businessLogo);
        }

        $businessLogoUrl = asset($businessLogo);
        $businessLogoPath = file_exists($localPath) ? $localPath : public_path('logo.png');

        $siteName = config('app.name') ?? 'Four Corner Funding';
        if ($this->resolvedMailConfig && $this->resolvedMailConfig->site_name) {
            $siteName = $this->resolvedMailConfig->site_name;
        }

        return new Content(
            view: 'emails.partner.mail_updated',
            with: array_merge([

                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'business_info' => [
                    'company_name'   => $siteName,
                    'email'  => $this->mailData['business_info']['email'] ?? '',
                    'phone_number'  => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1'  => $this->mailData['business_info']['address_line_1'] ?? '',
                    'business_logo_url'  => $businessLogoUrl,
                    'business_logo_path' => $businessLogoPath,
                ],
                'is_update' => $this->mailData['is_update'] ?? false,
            
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
