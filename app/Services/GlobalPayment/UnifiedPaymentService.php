<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        protected ?SubscriptionService $subscriptionService = null
    ) {
        $this->gatewayManager = $gatewayManager ?? app(PaymentGatewayManager::class);
        $this->paymentRecord = $paymentRecord ?? app(Payment::class);
        $this->subscriptionService = $subscriptionService ?? app(SubscriptionService::class);
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

        $isAuthorized = false;
        if ($invoice->entity_type === $user->entity_type && (int) $invoice->entity_id === (int) $user->entity_id) {
            $isAuthorized = true;
        } elseif (in_array($invoice->entity_type, [User::class, Employee::class]) && (int) $invoice->partner_id === (int) $user->partner_id) {
            $isAuthorized = true;
        }

        if (!$isAuthorized) {
            throw new Exception("Unauthorized invoice payment attempt.");
        }

        $partnerId       = $this->resolvePartnerId($invoice);
        $receiverId      = in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]) ? Partner::host()->id : $invoice->partner_id;
        $partnerProvider = $this->gatewayManager->resolvePartnerProvider($receiverId, $data['payment_method_id'] ?? null);
        $providerName    = $partnerProvider->provider?->name;

        $borrowerId = $invoice->entity_type === Borrower::class ? $invoice->entity_id : $invoice->borrower_id;

        $tokenPayload = is_string($data['payment_token'] ?? null) ? json_decode($data['payment_token'], true) : [];
        $isStripe = $providerName && strtolower(trim($providerName)) === 'stripe';
        $isManual = !$isStripe;

        if (is_array($tokenPayload) && isset($tokenPayload['mode']) && $tokenPayload['mode'] === 'manual') {
            $isManual = true;
        }

        DB::beginTransaction();
        try {
            // 1. Charge the payment gateway (or handle manual processing)
            if ($isManual) {
                $manualTx = !empty($tokenPayload['transaction_number'])
                    ? trim($tokenPayload['transaction_number'])
                    : ('MANUAL_TX_' . time() . '_' . rand(1000, 9999));

                $gatewayResult = [
                    'success'        => true,
                    'transaction_id' => $manualTx,
                    'raw'            => [
                        'mode'               => 'manual',
                        'provider'           => $providerName,
                        'transaction_number' => $tokenPayload['transaction_number'] ?? null,
                    ],
                ];
            } else {
                $driver        = $this->gatewayManager->driverFor($partnerProvider);
                $gatewayResult = $driver->charge([
                    'amount'            => $invoice->payment_amount,
                    'currency'          => $invoice->currency ?? 'USD',
                    'payment_method_id' => $data['payment_method_id'],
                    'payment_token'     => $data['payment_token'] ?? null,
                    'invoice_id'        => $invoice->id,
                    'description'       => "Payment for invoice #{$invoice->invoice_id}",
                ]);
            }

            if (empty($gatewayResult['success'])) {
                throw new Exception("Payment charge failed via " . ($providerName ?? 'gateway'));
            }

            $paymentStatus   = $isManual ? Payment::STATUS_PROCESSING : Payment::STATUS_SUCCEEDED;
            $invoiceStatus   = $isManual ? Invoice::STATUS_PROCESSING : Invoice::STATUS_PAID;
            $paymentType     = $data['payment_type'] ?? ($isStripe ? Payment::PAYMENT_TYPE_CARD : ($providerName ?? 'Bank Transfer'));
            $isPartnerPaying = (bool) ($invoice->is_partner_paying || in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]));

            // 2. Create local payment ledger entry
            $payment = $this->paymentRecord->createRecord([
                'partner_id'        => $partnerId,
                'borrower_id'       => $borrowerId,
                'invoice_id'        => $invoice->id,
                'entity_type'       => $invoice->entity_type,
                'entity_id'         => $invoice->entity_id,
                'transaction_id'    => $gatewayResult['transaction_id'] ?? ('TX_' . uniqid()),
                'amount'            => $invoice->payment_amount,
                'currency'          => strtoupper($invoice->currency ?? 'USD'),
                'payment_from'      => $providerName ?? 'Default',
                'payment_type'      => $paymentType,
                'is_partner_paying' => $isPartnerPaying,
                'is_manual'         => $isManual,
                'status'            => $paymentStatus,
                'details'           => $gatewayResult['raw'] ?? [],
            ]);

            // Save stripe_customer_id to payer model if returned in gateway response details
            $stripeCustId = $gatewayResult['raw']['customer'] ?? null;
            if ($stripeCustId && $invoice->entity && $invoice->entity->stripe_customer_id !== $stripeCustId) {
                $invoice->entity->update(['stripe_customer_id' => $stripeCustId]);
            }

            // 3. Update the invoice status
            $invoice->update([
                'status'       => $invoiceStatus,
                'payment_date' => $isManual ? null : now(),
            ]);

            // If it is a User or Employee seat invoice, update the partner's total_paid_users count on first payment
            $subscription = $invoice->subscription_id ? \App\Models\Subscription\Subscription::find($invoice->subscription_id) : null;
            if ($subscription && in_array($subscription->entity_type, [User::class, Employee::class])) {
                if (!$subscription->subscription_start_date) {
                    $partner = Partner::find($invoice->partner_id);
                    if ($partner) {
                        $partnerSubscription = $partner->subscription;
                        if ($partnerSubscription) {
                            $partnerSubscription->increment('user_seats');
                        }
                    }
                }
            }

            // 4. Advance the local subscription phase and schedule next billing date
            //    (Application-Led Model: Laravel is the source of truth for billing cycles)
            $this->subscriptionService->handleSuccessfulPayment($invoice);

            // 5. Auto charge platform fee if partner is certified/white-label and not is_host (only for non-manual / succeeded payments)
            if (!$isManual) {
                $this->triggerPartnerAutoChargeForBorrowerInvoice($invoice, $payment);
            }

            DB::commit();

            return [
                'success'        => true,
                'payment_id'     => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status'         => Payment::STATUS_SUCCEEDED,
                'details'        => $gatewayResult,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("[UnifiedPaymentService] Error: " . $e->getMessage(), ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Trigger automatic platform fee charge from partner to host for a borrower's invoice.
     */
    public function triggerPartnerAutoChargeForBorrowerInvoice(Invoice $invoice, ?Payment $payment = null): void
    {
        if ($invoice->entity_type === Borrower::class) {
            $partner = $invoice->partner;
            if ($partner && $partner->is_host == 0 && in_array($partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED]) && !$invoice->is_partner_paying) {
                $paymentMethodService = new PaymentMethodService();
                $settings = $paymentMethodService->getSettings($partner->id);
                if ($settings && $settings->partner_auto_charge_host_fee) {
                    Log::info('[AutoCharge] Charging platform fee for partner via Unified Flow', [
                        'invoice' => $invoice,
                        'partner' => $partner,
                        'payment' => $payment,
                    ]);
                    $this->chargePlatformFee($invoice, $partner, $payment);
                }
            }
        }
    }

    /**
     * Charge the platform fee if the partner is a White-Label or Certified Partner.
     */
    protected function chargePlatformFee(Invoice $invoice, Partner $partner, Payment $payment): void
    {
        try {
            $borrower = Borrower::find($invoice->entity_id);
            if ($borrower && !$borrower->partner_payment_for_me) {
                $query = Invoice::where('partner_id', $partner->id)
                    ->where('entity_type', Partner::class)
                    ->where('is_partner_paying', true)
                    ->where('status', Invoice::STATUS_UNPAID);

                if ($invoice->subscription_id) {
                    $partnerInvoice = $query->where('subscription_id', $invoice->subscription_id)->first();
                } else {
                    $partnerInvoice = $query->where('borrower_id', $borrower->id)->first();
                }

                if ($partnerInvoice) {
                    Log::info('Triggering automatic partner charge of ' . $partnerInvoice->payment_amount . ' to host for partner ' . $partner->id . ' upon borrower payment');
                    $success = $this->chargePartnerCardAutomatically($partner, $partnerInvoice, $partnerInvoice->payment_amount, $borrower, $payment);

                    if ($success) {
                        Log::info('Successfully automatically charged partner ' . $partner->id . ' fee of ' . $partnerInvoice->payment_amount);
                    } else {
                        Log::warning('Automatic partner charge of ' . $partnerInvoice->payment_amount . ' failed or could not find saved Stripe details.');
                    }
                } else {
                    Log::info('No unpaid partner invoice found for partner ' . $partner->id);
                }
            } else {
                Log::info('Skipping partner auto charge because this is host partner');
            }
        } catch (\Exception $e) {
            Log::error('Failed to automatically charge partner fee: ' . $e->getMessage());
        }
    }

    /**
     * Charge partner card automatically for WL Partner client.
     */
    protected function chargePartnerCardAutomatically(Partner $partner, Invoice $invoice, float $amount, Borrower $borrower = null, Payment $payment = null): bool
    {
        Log::info('[AutoCharge] Starting', [
            'partner_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);

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
                ->where('payment_from', Payment::PAYMENT_FROM_STRIPE)
                ->orderBy('id', 'desc')
                ->get()
                ->first(function ($p) {
                    return !empty($p->details['payment_method']);
                });

            $customerId = $partner->stripe_customer_id;
            if (!$customerId && $partnerPayment) {
                $customerId = $partnerPayment->details['customer'] ?? null;
            }

            if (!$customerId || !$partnerPayment || empty($partnerPayment->details['payment_method'])) {
                Log::warning('[AutoCharge] Could not find saved Stripe customer ID or payment method for partner ' . $partner->id);
                $this->recordFailedPayment($failedData, 'Could not find saved Stripe customer ID or payment method for partner', 'AutoCharge Missing Card');
                return false;
            }

            $paymentMethodId = $partnerPayment->details['payment_method'];

            Log::info('[AutoCharge] Partner payment located', [
                'payment_id' => $partnerPayment->id,
                'customer_id' => $customerId,
                'payment_method' => $paymentMethodId,
            ]);

            // ── Step 2: Fetch the Host's Stripe credentials
            $host = Partner::where('is_host', true)->first();
            if (!$host) {
                Log::error('[AutoCharge] Host partner not found for automatic billing.');
                $this->recordFailedPayment($failedData, 'Host partner not found for automatic billing.', 'AutoCharge Config Error');
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
                'description' => 'Partner enrollment fee — partner ' . $partner->id,
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

            Log::info('[AutoCharge] PaymentIntent created', [
                'pi_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
            ]);

            if (!in_array($paymentIntent->status, [Payment::STATUS_SUCCEEDED, Payment::STATUS_REQUIRES_CAPTURE])) {
                Log::error('[AutoCharge] PaymentIntent did not succeed', [
                    'pi_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                ]);
                $this->recordFailedPayment($failedData, 'PaymentIntent failed with status: ' . $paymentIntent->status, 'Stripe Payment Failed');
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

            Log::info('[AutoCharge] Partner invoice marked paid', [
                'invoice_id' => $invoice->id,
            ]);

            // ── Step 5: Record the payment ledger entry
            $this->paymentRecord->createRecord([
                'partner_id' => $partner->id,
                'borrower_id' => null,
                'invoice_id' => $invoice->id,
                'entity_type' => Partner::class,
                'entity_id' => (string) $partner->id,
                'transaction_id' => $paymentIntent->id,
                'payment_from' => Payment::PAYMENT_FROM_STRIPE,
                'payment_type' => Payment::PAYMENT_TYPE_CARD,
                'amount' => $amount,
                'currency' => Payment::CURRENCY_USD,
                'is_partner_paying' => true,
                'details' => $paymentIntent->toArray(),
                'status' => $paymentIntent->status,
                'transaction_date' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[AutoCharge] Exception: ' . $e->getMessage());
            $this->recordFailedPayment($failedData, $e->getMessage(), 'AutoCharge Exception');
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
        } catch (\Exception $e) {
            Log::error('Failed to record failed payment: ' . $e->getMessage());
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
