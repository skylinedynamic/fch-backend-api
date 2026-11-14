<?php

namespace App\Mail\Payment;

use App\Mail\Contracts\SendsImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
 use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Models\Partner\Partner;

class PaymentSuccessUserMail extends Mailable implements SendsImmediately
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

        // A paid seat's activation: the body is the welcome with the login
        // details, so the subject says so - not "Payment Receipt" (BUG-197).
        return new Envelope(
            subject: __('common.account_active_subject', ['site' => $this->dynamicData['siteName']]),
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? 'Valued Customer';
        $firstName = explode(' ', trim($userName))[0];
        $loginUrl = concatUrl(getPartnerDomain(), 'login', config('app.frontend_partner_url'));

        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        return new Content(
            view: 'emails.account_created',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'],
                'description' => $this->paymentData['description'] ?? 'Program Payment',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
                'user_name' => $userName,
                'user_email' => $this->paymentData['user_email'] ?? '',
                'first_name' => $firstName,
                'plainPassword' => $this->paymentData['plainPassword'] ?? null,
                'user' => $this->paymentData['user'] ?? null,
                'business_info' => $businessInfo,
                'loginUrl' => $loginUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
