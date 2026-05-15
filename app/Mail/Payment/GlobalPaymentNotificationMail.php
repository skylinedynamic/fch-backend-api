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
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject('New Payment Received'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.global_notification',
            with: array_merge([
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'],
                'user_name' => $this->paymentData['user_name'] ?? 'Unknown User',
                'user_email' => $this->paymentData['user_email'] ?? 'Unknown Email',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
