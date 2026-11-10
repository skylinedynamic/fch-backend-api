<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Contracts\Payment\AuthenticatesCardPayments;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\GlobalPayment\PaymentMailService;
use Exception;

/**
 * Class UnifiedPaymentService
 *
 * Coordinates a one-time payment charge against an invoice via the unified gateway
 * driver architecture (Stripe, Authorize.Net, Square, Ecrypt, etc.).
 *
 * After a successful charge:
 *  - Creates a local Payment ledger entry.
 *  - Marks the Invoice as Paid.
 *  - Calls SubscriptionService::handleSuccessfulPayment() to advance the local
 *    subscription phase and schedule the next billing date.
 */
class UnifiedPaymentService
{
    public function __construct(
        protected ?PaymentGatewayManager $gatewayManager = null,
        protected ?Payment $paymentRecord = null,
        protected ?SubscriptionService $subscriptionService = null,
        protected ?PaymentMailService $paymentMailService = null
    ) {
        $this->gatewayManager = $gatewayManager ?? app(PaymentGatewayManager::class);
        $this->paymentRecord = $paymentRecord ?? app(Payment::class);
        $this->subscriptionService = $subscriptionService ?? app(SubscriptionService::class);
        $this->paymentMailService = $paymentMailService ?? app(PaymentMailService::class);
    }

    /**
     * Process a one-time payment against an invoice via the unified gateway driver.
     * Amount, currency, description, and payee details are derived from the invoice.
     *
     * A card whose bank asks for 3-D Secure (BUG-066) does not fail here: the
     * attempt is kept as a PENDING payment and the result carries
     * `requires_action` and the `client_secret` the browser needs to run the
     * bank's challenge. confirmAuthenticatedPayment() settles it afterwards.
     *
     * @param array $data ['invoice_id' => int, 'payment_method_id' => string, 'payment_token' => string]
     * @return array
     * @throws Exception
     */
    public function processPayment(array $data): array
    {
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $subscription = $invoice->subscription;

        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            throw new Exception(__('payment.only_unpaid_can_be_paid'));
        }

        $this->assertMayPay($invoice);

        if ($invoice->type != Invoice::INVOICE_TYPE_BORROWER_FEE && $subscription && $subscription->current_cycle_invoice_id != $invoice->id) {
            throw new Exception(__('payment.unauthorized_payment_attempt'));
        }

        $partnerId = $this->resolvePartnerId($invoice);
        $receiverId = in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]) ? Partner::host()->id : $invoice->partner_id;
        $partnerProvider = $this->gatewayManager->resolvePartnerProvider($receiverId, $data['payment_method_id'] ?? null);
        $providerName = $partnerProvider->provider?->name;

        $borrowerId = $invoice->entity_type === Borrower::class ? $invoice->entity_id : $invoice->borrower_id;

        $tokenPayload = is_string($data['payment_token'] ?? null) ? json_decode($data['payment_token'], true) : [];
        $isStripe = $providerName && $providerName == PaymentProvider::STRIPE;
        $isManual = !$isStripe;

        if (is_array($tokenPayload) && isset($tokenPayload['mode']) && $tokenPayload['mode'] === 'manual') {
            $isManual = true;
        }

        $bankDetailId = $data['bank_detail_id'] ?? (is_array($tokenPayload) ? ($tokenPayload['bank_detail_id'] ?? null) : null);
        $bankDetail = $bankDetailId ? PaymentMethodBankDetail::find($bankDetailId) : null;

        $checkout = [
            'partner_id' => $partnerId,
            'borrower_id' => $borrowerId,
            'provider_name' => $providerName,
            'partner_provider' => $partnerProvider,
            'is_manual' => $isManual,
            'is_stripe' => $isStripe,
            'bank_detail' => $bankDetail,
            'payment_type' => $data['payment_type'] ?? null,
            // Who is paying - kept on the payment, and on the invoice once paid.
            'paid_by' => auth()->id(),
        ];

        // The gateway call deliberately runs OUTSIDE any transaction.
        //
        // A charge is not undone by a rollback. Holding a transaction open across
        // it meant that a failure anywhere in the ~130 lines that followed - a
        // subscription bug, an SMTP timeout, a deadlock - erased the record of
        // money that had already left the payer's account. The invoice stayed
        // Unpaid, so the next cron pass charged them again.
        //
        // So the order is: take the money, then record it in the shortest
        // transaction that will hold the records (completeCharge), then do
        // everything that can fail on its own afterwards.
        $gatewayResult = [];
        $driver = null;

        try {
            // 1. Charge the payment gateway (or handle manual processing)
            if ($isManual) {
                $isBankTransfer = strtolower(trim($providerName ?? '')) === strtolower(PaymentProvider::BANK_TRANSFER)
                    || (is_array($tokenPayload) && strtolower(trim($tokenPayload['gateway'] ?? '')) === strtolower(PaymentProvider::BANK_TRANSFER));

                if ($isBankTransfer && !$bankDetail) {
                    throw new Exception(__('payment.bank_account_required'));
                }

                $manualTx = !empty($tokenPayload['transaction_number'])
                    ? trim($tokenPayload['transaction_number'])
                    : ('MANUAL_TX_' . time() . '_' . rand(1000, 9999));

                $gatewayResult = [
                    'success' => true,
                    'transaction_id' => $manualTx,
                    'raw' => [
                        'mode' => 'manual',
                        'provider' => $providerName ?? PaymentProvider::BANK_TRANSFER,
                        'bank_detail_id' => $bankDetail?->id,
                        'bank_name' => $bankDetail?->bank_name,
                        'account_name' => $bankDetail?->account_name,
                        'account_number' => $bankDetail?->account_number,
                        'routing_number' => $bankDetail?->routing_number,
                        'instructions' => $bankDetail?->instructions,
                        'transaction_number' => is_array($tokenPayload) ? ($tokenPayload['transaction_number'] ?? null) : null,
                    ],
                ];
            } else {
                // A bank challenge left unfinished on this invoice is settled
                // before a new card is charged: cancelled if it is still waiting,
                // or - if the payer did complete it, in another tab - recorded,
                // which pays the invoice and stops this second charge.
                $this->supersedeAwaitingAuthentications($invoice);

                if ($invoice->refresh()->status !== Invoice::STATUS_UNPAID) {
                    throw new Exception(__('payment.only_unpaid_can_be_paid'));
                }

                $driver = $this->gatewayManager->driverFor($partnerProvider);
                try {
                    $gatewayResult = $driver->charge([
                        'amount' => $invoice->payment_amount,
                        'currency' => $invoice->currency ?? 'USD',
                        'payment_method_id' => $data['payment_method_id'],
                        'payment_token' => $data['payment_token'] ?? null,
                        'invoice_id' => $invoice->id,
                        'description' => __('payment.invoice_payment_description', ['invoice_id' => $invoice->invoice_id]),
                    ]);
                } catch (CardException $e) {
                    // Stripe answers a declined card with an error, not a result.
                    $this->recordCheckoutFailure($invoice, $partnerId, $borrowerId, $providerName, Payment::environmentOf($partnerProvider), $e->getMessage(), [
                        'code' => $e->getStripeCode(),
                        'decline_code' => $e->getDeclineCode(),
                    ]);
                    throw $e;
                }
            }

            if (empty($gatewayResult['success'])) {
                // A card the issuer wants authenticated (3-D Secure, typically an
                // OTP) is not a declined card. A gateway that can finish the
                // challenge in the browser gets it handed back there (BUG-066).
                if (!empty($gatewayResult['requires_action'])) {
                    if ($driver instanceof AuthenticatesCardPayments && !empty($gatewayResult['client_secret'])) {
                        return $this->awaitAuthentication($invoice, $checkout, $gatewayResult);
                    }

                    Log::warning('[UnifiedPayment] Card needs bank authentication (3-D Secure), which this gateway cannot finish', [
                        'invoice_id' => $invoice->id,
                        'transaction_id' => $gatewayResult['transaction_id'] ?? null,
                        'provider' => $providerName,
                    ]);

                    throw new Exception(__('payment.charge_requires_authentication'));
                }

                $message = __('payment.charge_failed', ['provider' => $providerName ?? __('payment.gateway')]);
                $this->recordCheckoutFailure($invoice, $partnerId, $borrowerId, $providerName, Payment::environmentOf($partnerProvider), $message, [
                    'gateway_status' => $gatewayResult['raw']['status'] ?? null,
                ], $gatewayResult['transaction_id'] ?? null);

                throw new Exception($message);
            }
        } catch (Exception $e) {
            Log::error(__('payment.log_unified_error', ['error' => $e->getMessage()]), ['data' => $data]);
            throw $e;
        }

        return $this->completeCharge($invoice, $checkout, $gatewayResult);
    }

    /**
     * Record a charge the gateway has accepted: the payment row, the invoice,
     * the payer's saved card, the subscription, the platform fee and the mail.
     *
     * Used straight after a charge, and again when a 3-D Secure charge comes
     * back authenticated - then `$pending` is the PENDING row kept for it, which
     * is settled in place rather than duplicated.
     *
     * @param array $checkout who pays whom, through what - see processPayment()
     */
    protected function completeCharge(Invoice $invoice, array $checkout, array $gatewayResult, ?Payment $pending = null): array
    {
        $subscription = $invoice->subscription;
        $isManual = (bool) $checkout['is_manual'];
        $isStripe = (bool) $checkout['is_stripe'];
        $providerName = $checkout['provider_name'];
        $partnerProvider = $checkout['partner_provider'];
        $partnerId = $checkout['partner_id'];
        $borrowerId = $checkout['borrower_id'];
        $bankDetail = $checkout['bank_detail'];

        // Tracked explicitly rather than inferred from DB::transactionLevel(),
        // which reports any transaction at all - including one a caller opened
        // around this method. Rolling that back would discard work this method
        // never owned.
        $transactionOpened = false;

        try {
            // The money has moved. From here the only work is recording it, so the
            // transaction spans the records and nothing else - no gateway calls,
            // no mail.
            DB::beginTransaction();
            $transactionOpened = true;

            if ($pending) {
                // The confirm endpoint and the settle command can reach the same
                // payment at once; whoever locks it first records it.
                $locked = Payment::whereKey($pending->id)->lockForUpdate()->first();

                if (!$locked || !$locked->isAwaitingAuthentication()) {
                    DB::rollBack();
                    $transactionOpened = false;

                    return $this->authenticationResult($locked ?? $pending);
                }

                $current = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

                if (!$current || $current->status !== Invoice::STATUS_UNPAID) {
                    // The bank took the money, but the invoice was settled some
                    // other way in the meantime. Record what was taken - it is
                    // real - and have it refunded; do not pay the invoice twice.
                    $locked->update([
                        'status' => Payment::STATUS_SUCCEEDED,
                        'details' => $this->settledDetails($locked, $gatewayResult, ['duplicate_charge' => true]),
                        'transaction_date' => now(),
                    ]);

                    DB::commit();
                    $transactionOpened = false;

                    Log::critical('[UnifiedPayment] Authenticated charge arrived for an invoice that is no longer unpaid - refund it', [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $locked->id,
                        'transaction_id' => $locked->transaction_id,
                    ]);

                    return $this->authenticationResult($locked->fresh());
                }

                $invoice = $current;
            }

            $paymentStatus = $isManual ? Payment::STATUS_PROCESSING : Payment::STATUS_SUCCEEDED;
            $invoiceStatus = $isManual ? Invoice::STATUS_PROCESSING : Invoice::STATUS_PAID;
            $paymentType = $checkout['payment_type'] ?? ($isStripe ? PaymentProvider::TYPE_CARD : ($providerName ?? __('payment.bank_transfer')));

            $isPartnerPaying = (bool) ($invoice->is_partner_paying || in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]));

            // 2. Create local payment ledger entry - or settle the one kept while
            //    the payer authenticated.
            if ($pending) {
                $locked->update([
                    'status' => $paymentStatus,
                    'payment_type' => $paymentType,
                    'is_partner_paying' => $isPartnerPaying,
                    'details' => $this->settledDetails($locked, $gatewayResult),
                    'transaction_date' => now(),
                ]);
                $payment = $locked;
            } else {
                $payment = $this->paymentRecord->createRecord([
                    'partner_id' => $partnerId,
                    'borrower_id' => $borrowerId,
                    'invoice_id' => $invoice->id,
                    'entity_type' => $invoice->entity_type,
                    'entity_id' => $invoice->entity_id,
                    'transaction_id' => $gatewayResult['transaction_id'] ?? ('TX_' . uniqid()),
                    'amount' => $invoice->payment_amount,
                    'currency' => strtoupper($invoice->currency ?? 'USD'),
                    'payment_from' => $providerName ?? __('payment.default_provider'),
                    'payment_type' => $paymentType,
                    'is_partner_paying' => $isPartnerPaying,
                    'is_manual' => $isManual,
                    'status' => $paymentStatus,
                    'is_environment' => Payment::environmentOf($partnerProvider),
                    'details' => $gatewayResult['raw'] ?? [],
                    'paid_by' => $checkout['paid_by'] ?? null,
                ]);
            }

            // Save default_payment_method and stripe_customer_id on first payment / when card details are missing
            $payer = $invoice->entity;

            if ($payer) {
                $rawCustId = $gatewayResult['customer_id'] ?? ($gatewayResult['raw']['customer'] ?? null);
                $cardName = $gatewayResult['card_name'] ?? null;
                $cardNo = $gatewayResult['card_no'] ?? null;

                if ($isStripe && $rawCustId) {
                    if (!$cardNo && !empty($gatewayResult['raw']['payment_method'])) {
                        $cardInfo = app(StripeCustomerService::class)->fetchCardDetails($gatewayResult['raw']['payment_method']);
                        $cardName = $cardName ?: ($cardInfo['name'] ?? null);
                        $cardNo = $cardNo ?: ($cardInfo['card_no'] ?? null);
                    }

                    $isSandbox = $partnerProvider->paymentMode() === PaymentProvider::MODE_SANDBOX;
                    $cardName = !empty($cardName) ? $cardName : ($isSandbox ? __('payment.sandbox_card') : null);

                    if (empty($payer->stripe_customer_id_raw) || empty($payer->stripe_customer_id['card_no'] ?? null)) {
                        $payer->update([
                            'default_payment_method' => PaymentProvider::STRIPE,
                            'stripe_customer_id' => [
                                'customer_id' => $rawCustId,
                                'name' => $cardName,
                                'card_no' => $cardNo,
                                'payment_mode' => $partnerProvider->paymentMode(),
                            ],
                        ]);
                    }
                } elseif (empty($payer->default_payment_method)) {
                    $payer->update([
                        'default_payment_method' => $providerName ?? PaymentProvider::CASH,
                    ]);
                }
            }

            // 3. Update the invoice status - and, once it is paid, who paid it.
            //    A manual payment gets its payer when it is approved.
            $invoice->update([
                'status' => $invoiceStatus,
                // payment_date is a DATE column - store the calendar date.
                'payment_date' => $isManual ? null : today(),
                ...($isManual ? [] : ['paid_by' => $payment->paid_by]),
            ]);

            // 4. If payment is successful (Paid/Success), make partner or borrower status Active
            $isPaidOrSuccess = in_array($invoiceStatus, [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS]);

            if (!$isManual && $isPaidOrSuccess && $payer) {
                if ($payer instanceof Partner && $payer->status !== Partner::STATUS_ACTIVE) {
                    $payer->update(['status' => Partner::STATUS_ACTIVE]);
                } elseif ($payer instanceof Borrower && $payer->status !== Borrower::STATUS_ACTIVE) {
                    $payer->update(['status' => Borrower::STATUS_ACTIVE]);
                }
            }


            $isFirstPayment = $subscription && empty($subscription->first_payment_date);

            // 5. Advance the local subscription phase and schedule next billing date
            //    (Application-Led Model: Laravel is the source of truth for billing cycles)
            $this->subscriptionService->handleSuccessfulPayment($invoice, $subscription);

            DB::commit();
            $transactionOpened = false;

            // Past this point the payment is durably recorded. Everything below is
            // a consequence of it, and none of it may undo it: step 6 makes a
            // second gateway call of its own, and step 7 talks to an SMTP server.

            // 6. Auto charge platform fee if partner is certified/white-label and not is_host (only for non-manual / succeeded payments)
            if (!$isManual && $isFirstPayment && $subscription?->entity_type === Borrower::class) {
                $borrowerId = $invoice->borrower_id ?? $subscription->entity_id;
                $borrowerFeeInvoice = Invoice::where(function ($q) use ($subscription, $borrowerId) {
                    if ($subscription?->id) {
                        $q->where('subscription_id', $subscription->id);
                    }
                    if (!empty($borrowerId)) {
                        $q->orWhere('borrower_id', $borrowerId);
                    }
                })
                    ->where('entity_type', Partner::class)
                    ->where('entity_id', $invoice->partner_id)
                    ->where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
                    ->where('status', Invoice::STATUS_UNPAID)
                    ->where('is_partner_paying', 1)
                    ->first();

                if ($borrowerFeeInvoice) {
                    $this->triggerPartnerAutoChargeForBorrowerInvoice($borrowerFeeInvoice);
                }
            }

            // 7. Send payment email to both payer and receiver via centralized PaymentMailService
            //
            // Caught and logged rather than thrown: the payer has paid and the
            // ledger says so, and a mail server having a bad minute is not a
            // reason to report that back as a failed payment.
            if ($payer) {
                try {
                    if ($isManual) {
                        $this->paymentMailService->sendSubmitted($payment, $invoice, $providerName, $bankDetail, $partnerId);
                    } elseif ($isPaidOrSuccess) {
                        $this->paymentMailService->sendSuccess($payment, $invoice, $providerName, $partnerId);
                    }
                } catch (Exception $e) {
                    Log::error('[UnifiedPayment] Payment recorded but its email could not be sent', [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => $gatewayResult,
            ];
        } catch (Exception $e) {
            // The failure may have happened before this method opened its
            // transaction, or after it committed one.
            if ($transactionOpened) {
                DB::rollBack();
            }

            // A charge that went through but could not be recorded is the one
            // failure here that costs the payer real money, and the generic log
            // below does not say what was taken. Name the gateway transaction so
            // it can be found and reconciled.
            if (!empty($gatewayResult['success']) && !empty($gatewayResult['transaction_id'])) {
                Log::critical('[UnifiedPayment] Charge succeeded but could not be recorded - reconcile manually', [
                    'invoice_id' => $invoice->id,
                    'transaction_id' => $gatewayResult['transaction_id'],
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::error(__('payment.log_unified_error', ['error' => $e->getMessage()]), [
                'invoice_id' => $invoice->id,
                'payment_id' => $pending?->id,
            ]);
            throw $e;
        }
    }

    /**
     * Only the payer of an invoice - or, for an invoice billed to a partner's
     * own user or employee, that partner's staff - may pay it or finish paying it.
     * The partner's own invoices may also be paid by its staff; the panel
     * offers Pay Now only to those holding Invoice::PERMISSION_PAY.
     *
     * @throws Exception
     */
    protected function assertMayPay(Invoice $invoice): void
    {
        $user = auth()->user();

        $isPayer = $user
            && $invoice->entity_type === $user->entity_type
            && (int) $invoice->entity_id === (int) $user->entity_id;

        $isPartnerStaff = $user
            && in_array($invoice->entity_type, [User::class, Employee::class])
            && (int) $invoice->partner_id === (int) $user->partner_id;

        // A partner's own invoice may also be paid by its staff (BUG-202).
        // "Pay Invoice" is checked by the panel only - it hides Pay Now (07-Nov
        // decision). Never a borrower's login, which shares the partner_id, and
        // never another partner's invoice.
        $mayPayForPartner = $user
            && $user->entity_type !== Borrower::class
            && $invoice->entity_type === Partner::class
            && (int) $invoice->entity_id === (int) $user->partner_id;

        if (!$isPayer && !$isPartnerStaff && !$mayPayForPartner) {
            throw new Exception(__('payment.unauthorized_payment_attempt'));
        }
    }

    // ── 3-D Secure (BUG-066) ────────────────────────────────────────────────

    /**
     * Keep a charge the issuer wants authenticated as a PENDING payment, and hand
     * the browser what it needs to run the bank's challenge.
     *
     * The client secret goes back to the payer only; it is not stored. The row
     * remembers which gateway account made the charge, so it is read back from
     * that same account whatever the partner's settings say by then.
     */
    protected function awaitAuthentication(Invoice $invoice, array $checkout, array $gatewayResult): array
    {
        $partnerProvider = $checkout['partner_provider'];

        $payment = $this->paymentRecord->createRecord([
            'partner_id' => $checkout['partner_id'],
            'borrower_id' => $checkout['borrower_id'],
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => $gatewayResult['transaction_id'],
            'amount' => $invoice->payment_amount,
            'currency' => strtoupper($invoice->currency ?? 'USD'),
            'payment_from' => $checkout['provider_name'] ?? __('payment.default_provider'),
            'payment_type' => PaymentProvider::TYPE_CARD,
            'status' => Payment::STATUS_PENDING,
            'is_environment' => Payment::environmentOf($partnerProvider),
            'paid_by' => $checkout['paid_by'] ?? null,
            'details' => [
                Payment::DETAIL_FAILURE_SOURCE => Payment::FAILURE_SOURCE_CHECKOUT,
                Payment::DETAIL_AWAITING => Payment::AWAITING_AUTHENTICATION,
                Payment::DETAIL_GATEWAY_ACCOUNT => $partnerProvider->id,
                'gateway_status' => $gatewayResult['status'] ?? null,
            ],
        ]);

        Log::info('[UnifiedPayment] Card needs bank authentication (3-D Secure); handed to the browser', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);

        return [
            'success' => false,
            'requires_action' => true,
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'client_secret' => $gatewayResult['client_secret'],
            'status' => Payment::STATUS_PENDING,
        ];
    }

    /**
     * The payer finished (or abandoned) the bank's challenge in the browser:
     * find out from the gateway how it ended, and settle the payment.
     *
     * Safe to call again - a payment already settled is reported as it stands,
     * without asking the gateway.
     *
     * @return array ['payment_id', 'transaction_id', 'status' => succeeded|pending]
     * @throws Exception when the payment cannot be confirmed by this user, or failed
     */
    public function confirmAuthenticatedPayment(int $paymentId): array
    {
        $payment = Payment::with('invoice')->find($paymentId);

        if (!$payment || !$payment->invoice) {
            throw new Exception(__('payment.not_found'));
        }

        $this->assertMayPay($payment->invoice);

        if ($payment->isAwaitingAuthentication()) {
            $result = $this->settleAwaitingAuthentication($payment);
        } elseif ($this->wasAwaitingAuthentication($payment)) {
            $result = $this->authenticationResult($payment);
        } else {
            throw new Exception(__('payment.not_awaiting_authentication'));
        }

        if ($result['status'] === Payment::STATUS_FAILED) {
            throw new Exception($result['message'] ?: __('payment.authentication_failed'));
        }

        return $result;
    }

    /**
     * Read an authentication-pending payment back from its gateway and settle it.
     *
     *  - authenticated        recorded as SUCCEEDED, the invoice is paid
     *  - refused by the bank  FAILED, with the gateway's reason
     *  - still waiting        left PENDING - or, with a $cancelReason, the charge
     *                         is cancelled at the gateway and the payment FAILED
     *
     * A charge still processing at the gateway (no longer waiting on the payer)
     * is never cancelled; it is left for a later pass.
     */
    public function settleAwaitingAuthentication(Payment $payment, ?string $cancelReason = null): array
    {
        if (!$payment->isAwaitingAuthentication()) {
            return $this->authenticationResult($payment);
        }

        $account = PartnerPaymentProvider::with('provider')->find($payment->details[Payment::DETAIL_GATEWAY_ACCOUNT] ?? null);
        $driver = $account ? $this->gatewayManager->driverFor($account) : null;

        if (!$driver instanceof AuthenticatesCardPayments) {
            throw new Exception(__('payment.authentication_gateway_missing'));
        }

        $intent = $driver->retrievePayment($payment->transaction_id);

        if (empty($intent['success']) && !empty($intent['requires_action']) && $cancelReason !== null) {
            $intent = $this->cancelAuthentication($driver, $payment);

            if (empty($intent['success'])) {
                return $this->failAwaitingAuthentication($payment, $cancelReason, $intent);
            }
        }

        if (!empty($intent['success'])) {
            // Read on its own, not through a relation loaded for a whole batch:
            // recording the payment walks the invoice's own relations.
            $invoice = Invoice::findOrFail($payment->invoice_id);

            return $this->completeCharge($invoice, [
                'partner_id' => $payment->partner_id,
                'borrower_id' => $payment->borrower_id,
                'provider_name' => $account->provider?->name ?? $payment->payment_from,
                'partner_provider' => $account,
                'is_manual' => false,
                'is_stripe' => true,
                'bank_detail' => null,
                'payment_type' => PaymentProvider::TYPE_CARD,
            ], $intent, $payment);
        }

        if (!empty($intent['requires_action']) || !empty($intent['processing'])) {
            return $this->authenticationResult($payment);
        }

        // A failed challenge reads, in Stripe's words, like a message to a
        // developer; the payer gets a plain one and the original is kept. A
        // decline after a passed challenge keeps the bank's reason - those are
        // written for cardholders ("Your card has insufficient funds.").
        $reason = ($intent['error_code'] ?? null) === StripePaymentDriver::ERROR_AUTHENTICATION_FAILED || empty($intent['error'])
            ? __('payment.authentication_failed')
            : $intent['error'];

        return $this->failAwaitingAuthentication($payment, $reason, $intent);
    }

    /**
     * Settle every bank challenge still open on the invoice before a new charge,
     * cancelling the ones still waiting.
     */
    protected function supersedeAwaitingAuthentications(Invoice $invoice): void
    {
        $open = Payment::awaitingAuthentication()->where('invoice_id', $invoice->id)->get();

        foreach ($open as $payment) {
            $this->settleAwaitingAuthentication($payment, __('payment.authentication_superseded'));
        }
    }

    /**
     * Cancel a charge waiting on authentication. The payer may have completed
     * the challenge a moment earlier, in which case the gateway refuses to
     * cancel it: then it is read again and reported as it now stands.
     */
    protected function cancelAuthentication(AuthenticatesCardPayments $driver, Payment $payment): array
    {
        try {
            return $driver->cancelPayment($payment->transaction_id);
        } catch (ApiErrorException $e) {
            Log::warning('[UnifiedPayment] Could not cancel a charge awaiting authentication; reading it again', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'error' => $e->getMessage(),
            ]);

            return $driver->retrievePayment($payment->transaction_id);
        }
    }

    protected function failAwaitingAuthentication(Payment $payment, string $reason, array $intent): array
    {
        return DB::transaction(function () use ($payment, $reason, $intent) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (!$locked->isAwaitingAuthentication()) {
                return $this->authenticationResult($locked);
            }

            $locked->update([
                'status' => Payment::STATUS_FAILED,
                'details' => $this->settledDetails($locked, [], array_filter([
                    'error' => $reason,
                    'gateway_error' => ($intent['error'] ?? null) !== $reason ? ($intent['error'] ?? null) : null,
                    'code' => $intent['error_code'] ?? null,
                    'gateway_status' => $intent['status'] ?? null,
                ], fn ($value) => $value !== null)),
            ]);

            return $this->authenticationResult($locked);
        });
    }

    /**
     * Details for a payment leaving the authentication wait: what the gateway
     * says now, still marked as a checkout payment from that gateway account,
     * without the waiting flag.
     */
    protected function settledDetails(Payment $payment, array $gatewayResult, array $extra = []): array
    {
        $details = $payment->details ?? [];

        $kept = array_intersect_key($details, array_flip([
            Payment::DETAIL_FAILURE_SOURCE,
            Payment::DETAIL_GATEWAY_ACCOUNT,
        ]));

        return array_merge($gatewayResult['raw'] ?? [], $kept, $extra);
    }

    /** A payment that went through the authentication wait and has left it. */
    protected function wasAwaitingAuthentication(Payment $payment): bool
    {
        return in_array($payment->status, [Payment::STATUS_SUCCEEDED, Payment::STATUS_FAILED], true)
            && ($payment->details[Payment::DETAIL_FAILURE_SOURCE] ?? null) === Payment::FAILURE_SOURCE_CHECKOUT
            && isset($payment->details[Payment::DETAIL_GATEWAY_ACCOUNT]);
    }

    protected function authenticationResult(Payment $payment): array
    {
        return [
            'success' => $payment->status === Payment::STATUS_SUCCEEDED,
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'status' => $payment->status,
            'message' => $payment->status === Payment::STATUS_FAILED ? ($payment->details['error'] ?? null) : null,
        ];
    }

    /**
     * Trigger automatic platform fee charge from partner to host for a borrower's invoice.
     */
    public function triggerPartnerAutoChargeForBorrowerInvoice(?Invoice $invoice, ?Payment $payment = null): void
    {
        if (!$invoice) {
            return;
        }

        $partner = $invoice->partner ?? Partner::find($invoice->entity_id);
        if ($partner) {
            $this->chargePlatformFee($invoice, $partner, $payment);
        }
    }

    /**
     * Charge the platform fee if the partner is a White-Label or Certified Partner.
     */
    protected function chargePlatformFee(Invoice $invoice, Partner $partner, ?Payment $payment = null): void
    {
        try {
            if ($invoice) {
                $borrower = $invoice->borrower ?? ($invoice->borrower_id ? Borrower::find($invoice->borrower_id) : null);
                $success = $this->chargePartnerCardAutomatically($partner, $invoice, (float) $invoice->payment_amount, $borrower, $payment);

                if ($success) {
                    Log::info(__('payment.log_autocharge_success_info', ['partner_id' => $partner->id, 'amount' => $invoice->payment_amount]));
                } else {
                    Log::warning(__('payment.log_autocharge_failed_warning', ['amount' => $invoice->payment_amount]));
                }
            }
        } catch (Exception $e) {
            Log::error(__('payment.log_autocharge_failed_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Charge partner card automatically for WL Partner client.
     */
    protected function chargePartnerCardAutomatically(Partner $partner, Invoice $invoice, float $amount, ?Borrower $borrower = null, ?Payment $payment = null): bool
    {
        // The partner's card is charged on the host's Stripe account, so that
        // account's mode is the environment - succeeded or failed.
        $host = Partner::where('is_host', true)->first();
        $stripe = PaymentProvider::where('name', PaymentProvider::STRIPE)->first();
        $hostStripe = $host && $stripe ? $this->gatewayManager->findPartnerProvider($host->id, $stripe->id) : null;
        $environment = Payment::environmentOf($hostStripe);

        $failedData = [
            'partner_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'amount' => $amount * 100,
            'currency' => Payment::CURRENCY_USD,
            'is_environment' => $environment,
        ];

        try {
            // Only a partner who chose Stripe as their default is charged
            // automatically; anyone else pays the fee by hand.
            if (strcasecmp((string) $partner->default_payment_method, PaymentProvider::STRIPE) !== 0) {
                $this->recordFailedPayment($failedData, __('payment.autocharge_default_not_stripe_msg'), __('payment.autocharge_default_not_stripe'));
                return false;
            }

            // ── Step 1: The card saved on the partner (My Subscription) - the one
            // the cron charges too. Past card payments are not where it lives: a
            // partner that paid in cash and then saved a card had none, so the fee
            // failed here and waited for the cron while the borrower stayed locked.
            $customerId = $partner->stripe_customer_id_raw;

            if (!$customerId) {
                Log::warning(__('payment.log_autocharge_missing_card_warning', ['partner_id' => $partner->id]));
                $this->recordFailedPayment($failedData, __('payment.autocharge_missing_card_msg'), __('payment.autocharge_missing_card'));
                return false;
            }

            // ── Step 2: The host's Stripe, which receives the fee
            if (!$hostStripe) {
                Log::error(__('payment.log_autocharge_host_not_found_error'));
                $this->recordFailedPayment($failedData, __('payment.autocharge_host_not_found_msg'), __('payment.autocharge_config_error'));
                return false;
            }

            // ── Step 3: Charge it off-session on the host's account - exactly as the
            // cron does, under the same idempotency reference, so the two paths can
            // never both take the same fee.
            $charge = $this->gatewayManager->driverFor($hostStripe)->chargeOffSession(
                $customerId,
                (int) round($amount * 100),
                Payment::CURRENCY_USD,
                __('payment.partner_enrollment_fee_desc', ['partner_id' => $partner->id]),
                'invoice_' . $invoice->id
            );

            if (empty($charge['success'])) {
                Log::error(__('payment.log_autocharge_pi_failed_error'), [
                    'pi_id' => $charge['transaction_id'] ?? null,
                    'status' => $charge['status'] ?? null,
                ]);
                $this->recordFailedPayment($failedData, __('payment.autocharge_pi_failed_msg', ['status' => $charge['status'] ?? 'unknown']), __('payment.stripe_payment_failed'));
                return false;
            }

            // ── Step 4: Mark the partner invoice as paid
            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                // payment_date is a DATE column - store the calendar date.
                'payment_date' => today(),
            ]);

            if ($borrower) {
                $borrower->update([
                    'partner_payment_for_me' => true,
                ]);
            }

            // ── Step 5: Record the payment ledger entry
            $partnerPayment = $this->paymentRecord->createRecord([
                'partner_id' => $partner->id,
                'borrower_id' => null,
                'invoice_id' => $invoice->id,
                'entity_type' => Partner::class,
                'entity_id' => (string) $partner->id,
                'transaction_id' => $charge['transaction_id'],
                'payment_from' => PaymentProvider::STRIPE,
                'payment_type' => PaymentProvider::TYPE_CARD,
                'amount' => $amount,
                'currency' => Payment::CURRENCY_USD,
                'is_partner_paying' => true,
                'is_environment' => $environment,
                'details' => $charge['raw'] ?? [],
                'status' => Payment::STATUS_SUCCEEDED,
                'transaction_date' => now(),
            ]);

            // ── Step 6: Send payment email notification (to Host Partner and Partner)
            $this->paymentMailService->sendSuccess($partnerPayment, $invoice, PaymentProvider::STRIPE, $partner->id);

            return true;
        } catch (Exception $e) {
            Log::error(__('payment.log_autocharge_exception_error', ['error' => $e->getMessage()]));
            $this->recordFailedPayment($failedData, $e->getMessage(), __('payment.autocharge_exception'));
            return false;
        }
    }

    /**
     * Record a card declined at checkout ("Pay Now") as a FAILED payment, so
     * every attempt shows in Payments as declined auto-charges do (BUG-035).
     * The invoice is left Unpaid. A recording error never hides the decline.
     */
    protected function recordCheckoutFailure(Invoice $invoice, ?int $partnerId, $borrowerId, ?string $providerName, int $environment, string $error, array $gateway = [], ?string $transactionId = null): void
    {
        try {
            $this->paymentRecord->createRecord([
                'partner_id' => $partnerId,
                'borrower_id' => $borrowerId,
                'invoice_id' => $invoice->id,
                'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id,
                'transaction_id' => $transactionId ?: 'FAILED_' . uniqid(),
                'amount' => $invoice->payment_amount,
                'currency' => strtoupper($invoice->currency ?? 'USD'),
                'payment_from' => $providerName ?? __('payment.default_provider'),
                'payment_type' => PaymentProvider::TYPE_CARD,
                'status' => Payment::STATUS_FAILED,
                'is_environment' => $environment,
                // A checkout is always someone paying by hand (BUG-202).
                'paid_by' => auth()->id(),
                'details' => array_filter([
                    Payment::DETAIL_FAILURE_SOURCE => Payment::FAILURE_SOURCE_CHECKOUT,
                    'error' => $error,
                ] + $gateway, fn ($value) => $value !== null),
            ]);
        } catch (Exception $e) {
            Log::error(__('payment.log_record_failed_payment_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Record failed payment details in the database.
     */
    protected function recordFailedPayment(array $data, string $errorMessage, string $errorType): void
    {
        try {
            $invoice = !empty($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

            $this->paymentRecord->createRecord([
                'partner_id' => $data['partner_id'] ?? null,
                'borrower_id' => $invoice->borrower_id ?? null,
                'invoice_id' => $invoice ? $data['invoice_id'] : null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'transaction_id' => 'FAILED_' . uniqid(),
                'amount' => ($data['amount'] ?? 0) / 100,
                'currency' => $data['currency'] ?? Payment::CURRENCY_USD,
                'details' => [
                    'error' => $errorMessage,
                    'type' => $errorType,
                    'input_data' => $data
                ],
                'status' => Payment::STATUS_FAILED,
                'is_environment' => $data['is_environment'] ?? null,
            ]);
        } catch (Exception $e) {
            Log::error(__('payment.log_record_failed_payment_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Resolve target partner ID from the invoice's payer.
     */
    protected function resolvePartnerId(Invoice $invoice): ?int
    {
        if ($invoice->partner_id) {
            return (int) $invoice->partner_id;
        }

        if ($invoice->entity_type === Partner::class && $invoice->entity_id) {
            return (int) $invoice->entity_id;
        }

        return Partner::where('is_host', true)->first()?->id;
    }
}
