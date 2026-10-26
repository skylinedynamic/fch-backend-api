<?php

namespace App\Mail\Payment;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * BUG-005: the scheduler stopped auto-charging an invoice after too many failed
 * attempts. Sent once, to the payer - and, for a client, to the client's
 * partner, who is told whose invoice it is (`for_partner`).
 */
class AutoChargeStoppedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        // Queued from the cron: no request to resolve the sender from, so the
        // caller names it (see PaymentFailedMail).
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
            subject: __('payment.auto_charge_stopped.subject', ['site' => $this->dynamicData['siteName']]),
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? __('payment.valued_customer');

        return new Content(
            view: 'emails.payment.auto_charge_stopped',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'first_name' => explode(' ', trim($userName))[0],
                'for_partner' => (bool) ($this->paymentData['for_partner'] ?? false),
                'client_name' => $this->paymentData['client_name'] ?? null,
                'invoice_id' => $this->paymentData['invoice_id'] ?? null,
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'] ?? 'USD',
                'description' => $this->paymentData['description'] ?? __('payment.program_payment'),
                'attempts' => (int) ($this->paymentData['attempts'] ?? 0),
                'portal_url' => $this->paymentData['portal_url'] ?? null,
                'company_name' => $this->dynamicData['siteName'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
