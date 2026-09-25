<?php

namespace App\Services\GlobalPayment;

use App\Mail\Payment\CardReauthorizationRequiredMail;
use App\Mail\Payment\PaymentApprovedMail;
use App\Mail\Payment\PaymentRejectedMail;
use App\Mail\Payment\PaymentSubmittedMail;
use App\Mail\Payment\PaymentSuccessMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * PaymentMailService
 *
 * Centralizes all payment notification email dispatch across the platform.
 * Handles payer/receiver resolution and dual delivery for all payment events:
 *   - Submitted (manual/bank transfer)
 *   - Success (Stripe / instant)
 *   - Approved (manual approval by admin/partner)
 *   - Rejected (manual rejection)
 */
class PaymentMailService
{
    // ──────────────────────────────────────────────
    // Public Dispatch Methods
    // ──────────────────────────────────────────────

    /**
     * Ask a payer to re-enter the card their automatic payments are charged to.
     *
     * Sent when the saved card belongs to a Stripe account the receiving partner
     * no longer transacts in. A Stripe Customer and its card live in exactly one
     * account - test or live - and cannot be moved between them, so no retry can
     * fix this; only the payer entering the card again can. Nothing else tells
     * them, so without this the cron just fails twice a day until someone notices
     * a month of unpaid invoices.
     *
     * @param Payment|null $payment The failed ledger entry, when one was written.
     */
    public function sendCardReauthorizationRequired(Invoice $invoice, ?Payment $payment = null): void
    {
        $payer = $invoice->entity;

        if (!$payer) {
            return;
        }

        $payerEmail = $payer->email ?? null;

        if (!$payerEmail) {
            Log::warning('[PaymentMail] Card re-authorization needed but the payer has no email', [
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        try {
            $sendingPartnerId = $this->resolveSendingPartnerId($payer);

            Mail::to($payerEmail)->queue(new CardReauthorizationRequiredMail([
                'partner_id' => $sendingPartnerId,
                'amount' => $invoice->payment_amount,
                'currency' => $invoice->currency ?? 'USD',
                'invoice_id' => $invoice->invoice_id ?: $invoice->id,
                'card_no' => is_array($payer->stripe_customer_id ?? null)
                    ? ($payer->stripe_customer_id['card_no'] ?? null)
                    : null,
                'description' => $this->resolveDescription($invoice, __('payment.program_payment')),
                'date' => now(),
                'portal_url' => $this->resolveBillingUrl($payer, $sendingPartnerId),
                'user_name' => $payer->full_name ?? $payer->company_name ?? __('payment.valued_customer'),
            ], $sendingPartnerId));
        } catch (\Exception $e) {
            Log::error(__('payment.log_mail_send_error', [
                'mail' => 'CardReauthorizationRequiredMail',
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Whose mailbox and branding a payer is written to under.
     *
     * Only a White-Label partner corresponds with its own people under its own
     * name; a Certified partner trades under the host's brand, and a partner
     * paying its own fees is the host's customer. This is the rule
     * getConfigPartner() applies to a request - restated against an explicit
     * payer because the cron that calls this has no authenticated user and no
     * request Origin for that helper to read, and would silently answer "host"
     * for everyone.
     */
    protected function resolveSendingPartnerId(object $payer): ?int
    {
        $hostId = Partner::where('is_host', 1)->value('id');

        // A partner's own fees are billed by the host, so the host addresses them.
        if ($payer instanceof Partner) {
            return $hostId;
        }

        $partner = $payer->partner ?? ($payer->partner_id ? Partner::find($payer->partner_id) : null);

        if ($partner && ($partner->is_host || $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)) {
            return $partner->id;
        }

        return $hostId;
    }

    /**
     * Where this payer updates their card - their own portal, not the other one.
     */
    protected function resolveBillingUrl(object $payer, ?int $sendingPartnerId): ?string
    {
        if ($payer instanceof Partner) {
            return concatUrl(getPartnerDomain($sendingPartnerId), 'my-subscription', config('app.frontend_partner_url'));
        }

        $borrowerDomain = getBorrowerDomain($payer->partner_id ?? $sendingPartnerId);

        // getBorrowerDomain() returns null for a White-Label partner with no
        // borrower domain of its own, where any link would land on a portal the
        // isolation check then refuses. Better no button than a broken one.
        return $borrowerDomain
            ? concatUrl($borrowerDomain, 'billing-details', config('app.frontend_borrower_url'))
            : null;
    }

    /**
     * Send "Payment Submitted" notification.
     * Triggered for all manual payments (bank transfer, cash, etc. — anything that is not Stripe).
     * Uses $payment->is_manual and $payment->payment_from as source of truth.
     */
    public function sendSubmitted(
        Payment $payment,
        Invoice $invoice,
        ?string $providerName = null,
        ?object $bankDetail = null,
        ?int $partnerId = null
    ): void {
        [$payerEmail, $payerName] = $this->resolvePayerInfo($payment, $invoice);
        [$receiver, $receiverEmail, $receiverName] = $this->resolveReceiver($payment, $invoice);

        if (!$payerEmail && !$receiverEmail) {
            return;
        }

        try {
            $receiverPartnerId = $receiver?->id ?: $partnerId ?: Partner::where('is_host', 1)->value('id');
            $companyName = $this->resolveCompanyName($payment, $invoice, $receiver);
            $invoiceDisplayId = $invoice->invoice_id ?: $invoice->id;

            $baseMailData = [
                'partner_id'     => $receiverPartnerId,
                'amount'         => $invoice->payment_amount,
                'currency'       => $invoice->currency ?? 'USD',
                'transaction_id' => $payment->transaction_id,
                'invoice_id'     => $invoiceDisplayId,
                'payment_method' => $providerName ?? $payment->payment_from ?? __('payment.bank_transfer'),
                'description'    => $this->resolveDescription($invoice, __('payment.program_payment')),
                'bank_name'      => $bankDetail?->bank_name,
                'account_name'   => $bankDetail?->account_name,
                'date'           => now(),
                'payer_name'     => $payerName ?: __('payment.valued_customer'),
                'payer_email'    => $payerEmail,
            ];

            // 1. Send to Payer (Customer Confirmation) - branding is Receiver's branding
            if ($payerEmail) {
                $payerMailData = array_merge($baseMailData, [
                    'is_receiver' => false,
                    'user_name'   => $payerName ?: __('payment.valued_customer'),
                    'subject'     => __('payment.mail_subject_payment_submitted', [
                        'company'    => $companyName,
                        'invoice_id' => $invoiceDisplayId,
                    ]),
                ]);
                Mail::to($payerEmail)->queue(new PaymentSubmittedMail($payerMailData, $receiverPartnerId));
            }

            // 2. Send to Receiver (Admin / Partner Action Alert) - branding is Receiver's branding
            if ($receiverEmail && $receiverEmail !== $payerEmail) {
                $paymentUrl = concatUrl(getPartnerDomain($receiverPartnerId), 'payments/' . $payment->id, config('app.frontend_partner_url'));

                $receiverMailData = array_merge($baseMailData, [
                    'is_receiver' => true,
                    'user_name'   => $receiverName ?: __('payment.admin'),
                    'payment_url' => $paymentUrl,
                    'subject'     => __('payment.mail_subject_payment_submitted_receiver', [
                        'company'    => $companyName,
                        'payer'      => $payerName ?: __('payment.customer'),
                        'invoice_id' => $invoiceDisplayId,
                    ]),
                ]);
                Mail::to($receiverEmail)->queue(new PaymentSubmittedMail($receiverMailData, $receiverPartnerId));
            }
        } catch (\Exception $e) {
            Log::error(__('payment.log_mail_send_error', ['mail' => 'PaymentSubmittedMail', 'error' => $e->getMessage()]));
        }
    }

    /**
     * Send "Payment Success" receipt.
     * Triggered for all instant (non-manual) payments — primarily Stripe.
     * payment_from column stores the actual provider name (e.g. "Stripe").
     */
    public function sendSuccess(
        Payment $payment,
        Invoice $invoice,
        ?string $providerName = null,
        ?int $partnerId = null
    ): void {
        [$payerEmail, $payerName] = $this->resolvePayerInfo($payment, $invoice);
        [$receiver, $receiverEmail, $receiverName] = $this->resolveReceiver($payment, $invoice);

        if (!$payerEmail && !$receiverEmail) {
            return;
        }

        try {
            $receiverPartnerId = $receiver?->id ?: $partnerId ?: Partner::where('is_host', 1)->value('id');
            $companyName = $this->resolveCompanyName($payment, $invoice, $receiver);
            $invoiceDisplayId = $invoice->invoice_id ?: $invoice->id;

            $baseMailData = [
                'partner_id'     => $receiverPartnerId,
                'amount'         => $invoice->payment_amount,
                'currency'       => $invoice->currency ?? 'USD',
                'transaction_id' => $payment->transaction_id,
                'invoice_id'     => $invoiceDisplayId,
                'payment_method' => $providerName ?? $payment->payment_from ?? PaymentProvider::STRIPE,
                'description'    => $this->resolveDescription($invoice, __('payment.program_payment')),
                'date'           => now(),
                'payer_name'     => $payerName ?: __('payment.valued_customer'),
                'payer_email'    => $payerEmail,
            ];

            // 1. Send to Payer (Customer Receipt) - branding is Receiver's branding
            if ($payerEmail) {
                $payerMailData = array_merge($baseMailData, [
                    'is_receiver' => false,
                    'user_name'   => $payerName ?: __('payment.valued_customer'),
                    'subject'     => __('payment.mail_subject_payment_receipt', [
                        'company'    => $companyName,
                        'invoice_id' => $invoiceDisplayId,
                    ]),
                ]);
                Mail::to($payerEmail)->queue(new PaymentSuccessMail($payerMailData, $receiverPartnerId));
            }

            // 2. Send to Receiver (Partner / Admin Payment Notice) - branding is Receiver's branding
            if ($receiverEmail && $receiverEmail !== $payerEmail) {
                $paymentUrl = concatUrl(getPartnerDomain($receiverPartnerId), 'payments/' . $payment->id, config('app.frontend_partner_url'));

                $receiverMailData = array_merge($baseMailData, [
                    'is_receiver' => true,
                    'user_name'   => $receiverName ?: __('payment.admin'),
                    'payment_url' => $paymentUrl,
                    'subject'     => __('payment.mail_subject_payment_receipt_receiver', [
                        'company'    => $companyName,
                        'payer'      => $payerName ?: __('payment.customer'),
                        'invoice_id' => $invoiceDisplayId,
                    ]),
                ]);
                Mail::to($receiverEmail)->queue(new PaymentSuccessMail($receiverMailData, $receiverPartnerId));
            }
        } catch (\Exception $e) {
            Log::error(__('payment.log_mail_send_error', ['mail' => 'PaymentSuccessMail', 'error' => $e->getMessage()]));
        }
    }

    /**
     * Send "Payment Approved" notification (manual payment approved by admin/partner).
     * Sent only to the payer.
     */
    public function sendApproved(
        Payment $payment,
        ?Invoice $invoice = null,
        ?string $note = null
    ): void {
        [$payerEmail, $payerName] = $this->resolvePayerInfo($payment, $invoice);
        [$receiver] = $this->resolveReceiver($payment, $invoice);

        if (!$payerEmail) {
            return;
        }

        try {
            $receiverPartnerId = $receiver?->id ?: Partner::where('is_host', 1)->value('id');
            $companyName = $this->resolveCompanyName($payment, $invoice, $receiver);
            $invoiceDisplayId = $invoice?->invoice_id ?: $invoice?->id;

            $mailData = [
                'partner_id'     => $receiverPartnerId,
                'amount'         => $payment->amount,
                'currency'       => $payment->currency ?? 'USD',
                'transaction_id' => $payment->transaction_id,
                'invoice_id'     => $invoiceDisplayId,
                'payment_method' => $payment->payment_from ?: __('payment.bank_transfer'),
                'description'    => $this->resolveDescription($invoice, __('payment.manual_payment_approved')),
                'approval_note'  => $note ?: null,
                'date'           => now(),
                'payer_name'     => $payerName ?: __('payment.valued_customer'),
                'payer_email'    => $payerEmail,
                'is_receiver'    => false,
                'user_name'      => $payerName ?: __('payment.valued_customer'),
                'subject'        => __('payment.mail_subject_payment_approved', [
                    'company'    => $companyName,
                    'invoice_id' => $invoiceDisplayId,
                ]),
            ];

            Mail::to($payerEmail)->queue(new PaymentApprovedMail($mailData, $receiverPartnerId));
        } catch (\Exception $e) {
            Log::error(__('payment.log_mail_send_error', ['mail' => 'PaymentApprovedMail', 'error' => $e->getMessage()]));
        }
    }

    /**
     * Send "Payment Rejected" notification.
     * Sent only to the payer.
     */
    public function sendRejected(
        Payment $payment,
        ?Invoice $invoice = null,
        string $reason = ''
    ): void {
        [$payerEmail, $payerName] = $this->resolvePayerInfo($payment, $invoice);
        [$receiver] = $this->resolveReceiver($payment, $invoice);

        if (!$payerEmail) {
            return;
        }

        try {
            $receiverPartnerId = $receiver?->id ?: Partner::where('is_host', 1)->value('id');
            $companyName = $this->resolveCompanyName($payment, $invoice, $receiver);
            $invoiceDisplayId = $invoice?->invoice_id ?: $invoice?->id;

            $mailData = [
                'partner_id'       => $receiverPartnerId,
                'amount'           => $payment->amount,
                'currency'         => $payment->currency ?? 'USD',
                'transaction_id'   => $payment->transaction_id,
                'invoice_id'       => $invoiceDisplayId,
                'payment_method'   => $payment->payment_from ?: __('payment.bank_transfer'),
                'description'      => $this->resolveDescription($invoice, __('payment.payment_verification')),
                'rejection_reason' => $reason,
                'date'             => now(),
                'payer_name'       => $payerName ?: __('payment.valued_customer'),
                'payer_email'      => $payerEmail,
                'is_receiver'      => false,
                'user_name'        => $payerName ?: __('payment.valued_customer'),
                'subject'          => __('payment.mail_subject_payment_rejected', [
                    'company'    => $companyName,
                    'invoice_id' => $invoiceDisplayId,
                ]),
            ];

            Mail::to($payerEmail)->queue(new PaymentRejectedMail($mailData, $receiverPartnerId));
        } catch (\Exception $e) {
            Log::error(__('payment.log_mail_send_error', ['mail' => 'PaymentRejectedMail', 'error' => $e->getMessage()]));
        }
    }

    // ──────────────────────────────────────────────
    // Private Resolution Helpers
    // ──────────────────────────────────────────────

    /**
     * Resolve payer email and display name from a Payment / Invoice entity.
     *
     * @return array{0: string|null, 1: string|null} [$email, $name]
     */
    private function resolvePayerInfo(Payment $payment, ?Invoice $invoice): array
    {
        $payer = $payment->entity ?: ($invoice?->entity);

        if ($payer instanceof Borrower) {
            $email = $payer->email ?: $invoice?->email;
            $name  = personName($payer->first_name ?? null, $payer->middle_name ?? null, $payer->last_name ?? null) ?: $payer->name;
        } elseif ($payer instanceof Partner) {
            $email = $payer->email ?: $payer->contact_person_email ?: $invoice?->email;
            $name  = $payer->full_name ?: $payer->company_name ?: $payer->name;
        } elseif ($payer instanceof User || $payer instanceof Employee) {
            $email = $payer->email;
            $name  = $payer->name;
        } else {
            $email = $invoice?->email;
            $name  = null;
        }

        return [$email ?: null, $name ? trim($name) ?: null : null];
    }

    /**
     * Resolve the receiver (the party receiving the payment) and their email and display name.
     *
     * Rule:
     *   - entity_type == Partner::class  → receiver is the Host Partner  (partner pays host)
     *   - entity_type == User/Employee   → receiver is the Host Partner
     *   - entity_type == Borrower::class → receiver is the invoice's Partner (borrower pays partner)
     *
     * @return array{0: Partner|null, 1: string|null, 2: string|null} [$receiver, $email, $name]
     */
    private function resolveReceiver(Payment $payment, ?Invoice $invoice): array
    {
        $entityType = $payment->entity_type ?: $invoice?->entity_type;

        if (in_array($entityType, [Partner::class, User::class, Employee::class])) {
            $receiver = Partner::host();
        } else {
            $receiver = $payment->partner
                ?: $invoice?->partner
                ?: ($payment->partner_id ? Partner::find($payment->partner_id) : null)
                ?: Partner::host();
        }

        $email = $receiver?->email ?: $receiver?->contact_person_email;
        $name  = $receiver?->full_name ?: $receiver?->company_name ?: $receiver?->name ?: __('payment.admin');

        return [$receiver, $email ?: null, $name ? trim($name) ?: null : null];
    }

    /**
     * Resolve the company name for the email branding context.
     */
    private function resolveCompanyName(Payment $payment, ?Invoice $invoice, ?Partner $receiver): string
    {
        $entityType = $payment->entity_type ?: $invoice?->entity_type;

        if ($entityType === Partner::class) {
            return $receiver?->company_name ?? config('app.name');
        }

        return $payment->partner?->company_name
            ?? $invoice?->partner?->company_name
            ?? config('app.name');
    }

    /**
     * Resolve the human-readable description from the invoice or return the fallback.
     */
    private function resolveDescription(?Invoice $invoice, string $fallback): string
    {
        if ($invoice && method_exists($invoice, 'getFormattedType')) {
            return $invoice->getFormattedType();
        }

        if ($invoice && !empty($invoice->type)) {
            return ucwords(str_replace(['_', '-'], ' ', $invoice->type));
        }

        return $fallback;
    }
}
