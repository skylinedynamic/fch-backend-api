<?php

namespace App\Mail\Payment;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentSuccessMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        $this->setMailPartnerId();

        Log::info('PaymentSuccessMail: Constructed', [
            'partner_id' => $partnerId,
            'transaction_id' => $this->paymentData['transaction_id'] ?? null,
            'amount' => $this->paymentData['amount'] ?? null,
        ]);
    }

    public function envelope(): Envelope
    {
        Log::info('PaymentSuccessMail: Envelope start');

        $branding = $this->getBrandingData();

        Log::info('PaymentSuccessMail: Branding loaded', [
            'siteName' => $branding['siteName'] ?? null,
            'logoUrl' => $branding['logoUrl'] ?? null,
        ]);

        $this->dynamicData['siteName'] = $branding['siteName'] ?? 'Unknown Site';
        $this->dynamicData['logoUrl'] = $branding['logoUrl'] ?? null;

        Log::info('PaymentSuccessMail: Envelope data set', [
            'siteName' => $this->dynamicData['siteName'],
        ]);

        $businessName = !empty($this->paymentData['business_name']) ? $this->paymentData['business_name'] : $this->dynamicData['siteName'];

        return new Envelope(
            subject: $this->paymentData['subject'] ?? ($businessName . " Enrollment Payment Receipt"),
        );
    }

    public function content(): Content
    {
        Log::info('PaymentSuccessMail: Content build started', [
            'payment_data' => [
                'transaction_id' => $this->paymentData['transaction_id'] ?? null,
                'amount' => $this->paymentData['amount'] ?? null,
                'currency' => $this->paymentData['currency'] ?? null,
            ],
        ]);

        $userName = $this->paymentData['user_name'] ?? 'Valued Customer';
        $firstName = explode(' ', trim($userName))[0] ?? 'Customer';

        Log::info('PaymentSuccessMail: User parsed', [
            'user_name' => $userName,
            'first_name' => $firstName,
        ]);

        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        Log::info('PaymentSuccessMail: Business info prepared', $businessInfo);

        $contentData = [
            'siteName' => $this->dynamicData['siteName'],
            'logoUrl' => $this->dynamicData['logoUrl'],
            'amount' => $this->paymentData['amount'],
            'currency' => $this->paymentData['currency'],
            'transaction_id' => $this->paymentData['transaction_id'],
            'description' => $this->paymentData['description'] ?? 'Program Payment',
            'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
            'user_name' => $userName,
            'first_name' => $firstName,
            'business_info' => $businessInfo,
        ];

        Log::info('PaymentSuccessMail: Final content data ready', [
            'transaction_id' => $this->paymentData['transaction_id'] ?? null,
        ]);

        return new Content(
            view: 'emails.payment.success',
            with: $contentData,
        );
    }

    public function attachments(): array
    {
        Log::info('PaymentSuccessMail: attachments called');

        return [];
    }
}