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
use Stripe\PaymentIntent;
use Stripe\Stripe;
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
        $this->gatewayManager     = $gatewayManager ?? app(PaymentGatewayManager::class);
        $this->paymentRecord      = $paymentRecord ?? app(Payment::class);
        $this->subscriptionService = $subscriptionService ?? app(SubscriptionService::class);
        $this->paymentMailService  = $paymentMailService ?? app(PaymentMailService::class);
    }

    /**
     * Process a one-time payment against an invoice via the unified gateway driver.
     * Amount, currency, description, and payee details are derived from the invoice.
     *
     * @param array $data ['invoice_id' => int, 'payment_method_id' => string, 'payment_token' => string]
     * @return array
     * @throws Exception
     */
    public function processPayment(array $data): array
    {
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $user = auth()->user();
        $subscription = $invoice->subscription;

        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            throw new Exception(__('payment.only_unpaid_can_be_paid'));
        }

        $isAuthorized = false;
        if ($invoice->entity_type === $user->entity_type && (int) $invoice->entity_id === (int) $user->entity_id) {
            $isAuthorized = true;
        } elseif (in_array($invoice->entity_type, [User::class, Employee::class]) && (int) $invoice->partner_id === (int) $user->partner_id) {
            $isAuthorized = true;
        }

        if (!$isAuthorized) {
            throw new Exception(__('payment.unauthorized_payment_attempt'));
        }

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

        DB::beginTransaction();
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
                $driver = $this->gatewayManager->driverFor($partnerProvider);
                $gatewayResult = $driver->charge([
                    'amount' => $invoice->payment_amount,
                    'currency' => $invoice->currency ?? 'USD',
                    'payment_method_id' => $data['payment_method_id'],
                    'payment_token' => $data['payment_token'] ?? null,
                    'invoice_id' => $invoice->id,
                    'description' => __('payment.invoice_payment_description', ['invoice_id' => $invoice->invoice_id]),
                ]);
            }

            if (empty($gatewayResult['success'])) {
                throw new Exception(__('payment.charge_failed', ['provider' => $providerName ?? __('payment.gateway')]));
            }

            $paymentStatus = $isManual ? Payment::STATUS_PROCESSING : Payment::STATUS_SUCCEEDED;
            $invoiceStatus = $isManual ? Invoice::STATUS_PROCESSING : Invoice::STATUS_PAID;
            $paymentType = $data['payment_type'] ?? ($isStripe ? PaymentProvider::TYPE_CARD : ($providerName ?? __('payment.bank_transfer')));

            $isPartnerPaying = (bool) ($invoice->is_partner_paying || in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]));

            // 2. Create local payment ledger entry
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
                'details' => $gatewayResult['raw'] ?? [],
            ]);

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

                    $isSandbox = ($partnerProvider->payment_mode ?? PaymentProvider::MODE_SANDBOX) === PaymentProvider::MODE_SANDBOX;
                    $cardName = !empty($cardName) ? $cardName : ($isSandbox ? __('payment.sandbox_card') : null);

                    if (empty($payer->stripe_customer_id_raw) || empty($payer->stripe_customer_id['card_no'] ?? null)) {
                        $payer->update([
                            'default_payment_method' => PaymentProvider::STRIPE,
                            'stripe_customer_id' => [
                                'customer_id' => $rawCustId,
                                'name' => $cardName,
                                'card_no' => $cardNo,
                            ],
                        ]);
                    }
                } elseif (empty($payer->default_payment_method)) {
                    $payer->update([
                        'default_payment_method' => $providerName ?? PaymentProvider::CASH,
                    ]);
                }
            }

            // 3. Update the invoice status
            $invoice->update([
                'status' => $invoiceStatus,
                'payment_date' => $isManual ? null : now(),
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


            $isFirstPaymentPaid = !empty($subscription->first_payment_date);

            // 5. Advance the local subscription phase and schedule next billing date
            //    (Application-Led Model: Laravel is the source of truth for billing cycles)
            $this->subscriptionService->handleSuccessfulPayment($invoice, $subscription);

            // 6. Auto charge platform fee if partner is certified/white-label and not is_host (only for non-manual / succeeded payments)
            if (!$isManual && !$isFirstPaymentPaid && $subscription->entity_type === Borrower::class) {
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
            if ($payer) {
                if ($isManual) {
                    $this->paymentMailService->sendSubmitted($payment, $invoice, $providerName, $bankDetail, $partnerId);
                } elseif ($isPaidOrSuccess) {
                    $this->paymentMailService->sendSuccess($payment, $invoice, $providerName, $partnerId);
                }
            }

            DB::commit();

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => $gatewayResult,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error(__('payment.log_unified_error', ['error' => $e->getMessage()]), ['data' => $data]);
            throw $e;
        }
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
        $failedData = [
            'partner_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'amount' => $amount * 100,
            'currency' => Payment::CURRENCY_USD,
        ];

        try {
            // ── Step 1: Find a successful payment from this partner to get their default payment method
            $partnerPayment = Payment::where('entity_type', Partner::class)
                ->where('entity_id', $partner->id)
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->where('payment_from', PaymentProvider::STRIPE)
                ->orderBy('id', 'desc')
                ->get()
                ->first(function ($p) {
                    return !empty($p->details['payment_method']);
                });

            $customerId = $partner->stripe_customer_id_raw;
            if (!$customerId && $partnerPayment) {
                $customerId = $partnerPayment->details['customer'] ?? null;
            }

            if (!$customerId || !$partnerPayment || empty($partnerPayment->details['payment_method'])) {
                Log::warning(__('payment.log_autocharge_missing_card_warning', ['partner_id' => $partner->id]));
                $this->recordFailedPayment($failedData, __('payment.autocharge_missing_card_msg'), __('payment.autocharge_missing_card'));
                return false;
            }

            $paymentMethodId = $partnerPayment->details['payment_method'];

            // ── Step 2: Fetch the Host's Stripe credentials
            $host = Partner::where('is_host', true)->first();
            if (!$host) {
                Log::error(__('payment.log_autocharge_host_not_found_error'));
                $this->recordFailedPayment($failedData, __('payment.autocharge_host_not_found_msg'), __('payment.autocharge_config_error'));
                return false;
            }

            $this->paymentRecord->configureStripeForPartner($host->id);

            // ── Step 3: Create off-session PaymentIntent on the Host's Stripe account
            $paymentIntent = PaymentIntent::create([
                'amount' => (int) round($amount * 100),
                'currency' => Payment::CURRENCY_USD,
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'description' => __('payment.partner_enrollment_fee_desc', ['partner_id' => $partner->id]),
                'metadata' => [
                    'partner_id' => $partner->id,
                    'invoice_id' => $invoice->id,
                    'auto_charge' => 'true',
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
            ]);

            if (!in_array($paymentIntent->status, [Payment::STATUS_SUCCEEDED, Payment::STATUS_REQUIRES_CAPTURE])) {
                Log::error(__('payment.log_autocharge_pi_failed_error'), [
                    'pi_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                ]);
                $this->recordFailedPayment($failedData, __('payment.autocharge_pi_failed_msg', ['status' => $paymentIntent->status]), __('payment.stripe_payment_failed'));
                return false;
            }

            // ── Step 4: Mark the partner invoice as paid
            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'payment_date' => now(),
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
                'transaction_id' => $paymentIntent->id,
                'payment_from' => PaymentProvider::STRIPE,
                'payment_type' => PaymentProvider::TYPE_CARD,
                'amount' => $amount,
                'currency' => Payment::CURRENCY_USD,
                'is_partner_paying' => true,
                'details' => $paymentIntent->toArray(),
                'status' => $paymentIntent->status,
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
