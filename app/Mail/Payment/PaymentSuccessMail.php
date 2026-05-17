<?php

namespace App\Mail\Payment;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Models\Partner\Partner;

class PaymentSuccessMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        $this->resolveMailConfig($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];
        
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject("Payment Receipt - {$siteName}"),
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? 'Valued Customer';
        $firstName = explode(' ', trim($userName))[0];

        return new Content(
            view: 'emails.payment.success',
            with: array_merge([
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'],
                'description' => $this->paymentData['description'] ?? 'Program Payment',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
                'user_name' => $userName,
                'first_name' => $firstName,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
