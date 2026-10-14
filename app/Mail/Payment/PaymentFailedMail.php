<?php

namespace App\Mail\Payment;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Models\Partner\Partner;

class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        // The argument used to be accepted and dropped, unlike every sibling
        // mailable. It matters most where it was least visible: queued from the
        // auto-charge cron there is no authenticated user and no request Origin
        // for setMailPartnerId() to resolve from, so it fell back to the host and
        // a White-Label partner's borrower was written to from the host's mailbox.
        if ($partnerId) {
            $this->mailPartnerId = $partnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: "Payment Failed - " . $this->dynamicData['siteName'],
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? 'Valued Customer';
        $firstName = explode(' ', trim($userName))[0];

        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        return new Content(
            view: 'emails.payment.failed',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'transaction_id' => $this->paymentData['transaction_id'] ?? null,
                // Sent by the auto-charge cron (findings O46): the invoice it
                // could not take, whether there was a card at all, and where
                // to update the method or pay.
                'invoice_id' => $this->paymentData['invoice_id'] ?? null,
                'no_saved_card' => (bool) ($this->paymentData['no_saved_card'] ?? false),
                'portal_url' => $this->paymentData['portal_url'] ?? null,
                'description' => $this->paymentData['description'] ?? 'Program Payment',
                'date' => $this->paymentData['date']->format('F j, Y, g:i a'),
                'user_name' => $userName,
                'first_name' => $firstName,
                'business_info' => $businessInfo,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
