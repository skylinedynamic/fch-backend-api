<?php

namespace App\Mail\Payment;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Models\Partner\Partner;

class GlobalPaymentNotificationMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        $this->resolveMailConfig($partnerId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Payment Received',
        );
    }

    public function content(): Content
    {
        $siteName = config('app.name') ?? 'Four Corner Funding';
        if ($this->resolvedMailConfig && $this->resolvedMailConfig->site_name) {
            $siteName = $this->resolvedMailConfig->site_name;
        }

        $hostPartner = Partner::where('is_host', 1)->first();
        $businessInfo = [
            'company_name' => $siteName,
            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
            'email' => $hostPartner->email ?? config('mail.from.address'),
            'phone_number' => $hostPartner->phone_number ?? '',
            'address_line_1' => $hostPartner->address_line_1 ?? '',
        ];

        // ✅ Handle business logo properly
        $businessLogo = $businessInfo['company_logo'];

        // If full URL was provided, convert to filename
        if (str_starts_with($businessLogo, 'http')) {
            $fileName = basename(parse_url($businessLogo, PHP_URL_PATH));
            $localPath = public_path($fileName);
        } else {
            $localPath = public_path($businessLogo);
        }

        // ✅ Prepare URLs and paths
        $businessInfo['business_logo_url'] = asset($businessLogo);
        $businessInfo['business_logo_path'] = file_exists($localPath) ? $localPath : public_path('logo.png');

        return new Content(
            view: 'emails.payment.global_notification',
            with: array_merge([

                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'],
                'user_name' => $this->paymentData['user_name'] ?? 'Unknown User',
                'user_email' => $this->paymentData['user_email'] ?? 'Unknown Email',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
                'business_info' => $businessInfo,
            
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
