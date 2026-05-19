<?php

namespace App\Mail\Payment;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Models\Partner\Partner;

class GlobalPaymentNotificationMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        $this->setMailPartnerId();
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'New Payment Received',
        );
    }

    public function content(): Content
    {
        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        return new Content(
            view: 'emails.payment.global_notification',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'],
                'user_name' => $this->paymentData['user_name'] ?? 'Unknown User',
                'user_email' => $this->paymentData['user_email'] ?? 'Unknown Email',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
                'business_info' => $businessInfo,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
