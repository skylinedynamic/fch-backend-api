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

class PaymentRejectedMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        if ($partnerId) {
            $this->mailPartnerId = $partnerId;
        } else {
            $this->setMailPartnerId();
        }

        Log::info(__('payment.log_mail_constructed', ['mail' => 'PaymentRejectedMail']), [
            'partner_id' => $this->mailPartnerId,
            'transaction_id' => $this->paymentData['transaction_id'] ?? null,
            'amount' => $this->paymentData['amount'] ?? null,
        ]);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();

        $this->dynamicData['siteName'] = $branding['siteName'] ?? config('app.name');
        $this->dynamicData['logoUrl'] = $branding['logoUrl'] ?? null;

        $businessName = !empty($this->paymentData['business_name']) ? $this->paymentData['business_name'] : $this->dynamicData['siteName'];
        $subject = $this->paymentData['subject'] ?? __('payment.mail_subject_default_rejected', ['company' => $businessName]);

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? __('payment.valued_customer');
        $firstName = explode(' ', trim($userName))[0] ?? __('payment.customer');

        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        $rawDate = $this->paymentData['date'] ?? now();
        $formattedDate = ($rawDate instanceof \Carbon\Carbon || $rawDate instanceof \DateTimeInterface)
            ? $rawDate->format('F j, Y, g:i a')
            : (is_string($rawDate) ? $rawDate : now()->format('F j, Y, g:i a'));

        $contentData = [
            'siteName' => $this->dynamicData['siteName'],
            'logoUrl' => $this->dynamicData['logoUrl'],
            'amount' => $this->paymentData['amount'] ?? 0,
            'currency' => $this->paymentData['currency'] ?? 'USD',
            'transaction_id' => $this->paymentData['transaction_id'] ?? __('payment.na'),
            'invoice_id' => $this->paymentData['invoice_id'] ?? null,
            'payment_method' => $this->paymentData['payment_method'] ?? __('payment.bank_transfer'),
            'description' => $this->paymentData['description'] ?? __('payment.program_payment'),
            'rejection_reason' => $this->paymentData['rejection_reason'] ?? __('payment.default_rejection_reason'),
            'date' => $formattedDate,
            'user_name' => $userName,
            'first_name' => $firstName,
            'business_info' => $businessInfo,
        ];

        return new Content(
            view: 'emails.payment.rejected',
            with: $contentData,
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
