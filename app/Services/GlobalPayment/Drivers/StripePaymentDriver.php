<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\AuthenticatesCardPayments;
use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Support\Payment\PaymentCredentials;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Stripe\PaymentMethod as StripePaymentMethod;
use RuntimeException;

/**
 * Application-Led Stripe Payment Driver
 *
 * This driver is responsible for:
 * 1. Resolving/creating a Stripe Customer and attaching the payment method.
 * 2. Charging the customer a one-time PaymentIntent for the given invoice amount.
 *
 * Stripe Subscription Schedules are NOT used in this architecture.
 * The application (Laravel) manages billing cycles, installments, and platform fees entirely.
 * Due payments are automatically charged off-session by the `subscriptions:process-all` cron job.
 */
class StripePaymentDriver implements PaymentGatewayInterface, AuthenticatesCardPayments
{
    /** PaymentIntent statuses that mean the money has been taken (or authorised). */
    public const INTENT_PAID_STATUSES = ['succeeded', 'requires_capture'];

    /** The issuer wants the cardholder to authenticate - 3-D Secure, usually an OTP. */
    public const INTENT_REQUIRES_ACTION = 'requires_action';

    /**
     * Not settled yet, but no longer waiting on the payer: Stripe is still
     * processing an authenticated charge. Kept pending, never failed.
     */
    public const INTENT_PROCESSING = 'processing';

    /** last_payment_error.code when the cardholder failed (or abandoned) the bank's challenge. */
    public const ERROR_AUTHENTICATION_FAILED = 'payment_intent_authentication_failure';

    protected array $config = [];
    protected string $environment = PaymentProvider::MODE_SANDBOX;
    protected ?string $apiKey = null;

    /**
     * A key that makes repeating the SAME charge attempt harmless.
     *
     * Stripe remembers an idempotency key for 24 hours: a second request
     * carrying one it has already seen is not charged again, it simply replays
     * the first response. That is the difference between a retry and a second
     * debit.
     *
     * It matters here because a charge can succeed and still be lost. A failure
     * anywhere after the money moves - a queue retry, a rolled back transaction -
     * leaves the invoice Unpaid, and the next pass charges a card that has
     * already paid.
     *
     * What goes into the key decides what counts as "the same attempt":
     *
     *   invoice   the debt being settled - a different invoice is a real charge
     *   amount    Stripe rejects a reused key carrying different parameters, so
     *   currency  a changed amount has to produce a different key, not an error
     *   instrument the card or customer being charged - so a payer whose card was
     *             declined can immediately retry with another one, which a key
     *             built from the invoice alone would block for 24 hours
     *
     * @param string $scope Distinguishes the calling flow, so an interactive
     *                      charge and an auto-charge never collide.
     */
    protected function idempotencyKey(string $scope, array $parts): string
    {
        return $scope . '_' . substr(hash('sha256', implode('|', $parts)), 0, 40);
    }

    public function initialize(array $config, string $environment = PaymentProvider::MODE_SANDBOX): self
    {
        $this->config = $config;
        $this->environment = PaymentCredentials::normalizeMode($environment);

        $this->apiKey = PaymentCredentials::secretKey($config, $this->environment);

        if (!$this->apiKey) {
            throw new RuntimeException("Stripe secret key not configured for {$this->environment} mode.");
        }

        Stripe::setApiKey($this->apiKey);

        return $this;
    }

    /**
     * Charge a one-time PaymentIntent for the given invoice amount.
     *
     * Steps:
     *  1. Resolve or create the Stripe Customer using the payer's email.
     *  2. Attach and set the provided payment method (pm_xxx) as the default on the customer.
     *  3. Create and confirm a PaymentIntent for the invoice amount.
     *
     * @param array $params
     * @return array
     */
    public function charge(array $params): array
    {
        $amountInCents = (int) round(($params['amount'] ?? 0) * 100);
        // Stripe takes the currency in lower case.
        $currency = strtolower($params['currency'] ?? Payment::CURRENCY_USD);

        // 1. Resolve invoice and payer
        $invoice = Invoice::find($params['invoice_id']);
        if (!$invoice) {
            throw new RuntimeException("Invoice not found.");
        }

        $payer = $invoice->entity;
        $stripeCustomerData = $payer?->stripe_customer_id;
        $stripeCustomerIdRaw = $payer?->stripe_customer_id_raw;

        // 2. Resolve or create Stripe Customer
        $customerService = app(StripeCustomerService::class);
        $customerId = $customerService->resolveCustomer(
            $invoice->email,
            $invoice->customer_name ?? 'Client',
            $stripeCustomerData,
            $this->environment
        );

        // 3. The card from the browser. It is attached and made the customer's
        //    default - the card the auto-charge uses - only when the payer ticked
        //    "Save card as default payment method"; otherwise it only pays this.
        $saveCard = !empty($params['save_card']);
        $paymentMethodId = null;
        $cardDetails = ['name' => null, 'card_no' => null];
        if (!empty($params['payment_token']) && str_starts_with($params['payment_token'], 'pm_')) {
            $paymentMethodId = $params['payment_token'];
            if ($saveCard) {
                $customerService->setDefaultPaymentMethod($customerId, $paymentMethodId);
            }
            $cardDetails = $customerService->fetchCardDetails($paymentMethodId);
        }

        // 4. Build PaymentIntent parameters
        $intentParams = [
            'amount' => $amountInCents,
            'currency' => $currency,
            'customer' => $customerId,
            'description' => $params['description'] ?? "Payment for Invoice #{$invoice->invoice_id}",
            'confirm' => true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ];

        if ($paymentMethodId) {
            $intentParams['payment_method'] = $paymentMethodId;
        }

        // 5. Create & confirm the PaymentIntent
        $intent = PaymentIntent::create($intentParams, [
            'idempotency_key' => $this->idempotencyKey('charge', [
                $invoice->id,
                $amountInCents,
                $currency,
                $paymentMethodId ?? $customerId,
            ]),
        ]);

        if (!$cardDetails['card_no'] && !empty($intent->payment_method)) {
            $cardDetails = $customerService->fetchCardDetails($intent->payment_method);
        }

        $isSandbox = $this->environment === PaymentProvider::MODE_SANDBOX;
        $cardName = !empty($cardDetails['name']) ? $cardDetails['name'] : ($isSandbox ? 'Sandbox Card' : null);
        $cardNo = $cardDetails['card_no'];

        // Persist the Stripe Customer this charge actually used.
        //
        // resolveCustomer() discards a customer belonging to the other Stripe
        // account and resolves a fresh one, so after a mode switch the id used
        // here is NOT the one on file. Writing back only when the record looked
        // empty left the stale id in place, and the next payment repeated the
        // whole rediscovery - so the record is also rewritten whenever the
        // customer or the mode has moved on.
        //
        // Rewriting on a mode change is what backfills `payment_mode` onto rows
        // saved before it was recorded, which is what lets the cross-account
        // guards recognize them later.
        $stored = is_array($payer?->stripe_customer_id) ? $payer->stripe_customer_id : [];
        $storedCustomerId = $stored['customer_id'] ?? (is_string($payer?->stripe_customer_id) ? $payer->stripe_customer_id : null);
        $customerMoved = $storedCustomerId !== $customerId;

        $isStale = $customerMoved || ($stored['payment_mode'] ?? null) !== $this->environment;
        $isIncomplete = empty($storedCustomerId) || empty($stored['card_no'] ?? null);

        // Only a card the payer chose to save is written - and then it replaces
        // the one on file. Unsaved, the record is left as it was: resolveCustomer()
        // finds the customer again by email, so no duplicate is created.
        if ($payer && $saveCard && $paymentMethodId) {
            $payer->update([
                'default_payment_method' => PaymentProvider::STRIPE,
                'stripe_customer_id' => [
                    'customer_id'  => $customerId,
                    'name'         => $cardName,
                    'card_no'      => $cardNo,
                    'payment_mode' => $this->environment,
                ],
            ]);
        } elseif ($payer && $saveCard && ($isStale || $isIncomplete)) {
            // Card details already on file describe the OLD customer's card, so
            // they are only worth keeping when the customer itself has not moved.
            $keepIfSameCustomer = fn (string $key) => $customerMoved ? null : ($stored[$key] ?? null);

            $payer->update([
                'default_payment_method' => PaymentProvider::STRIPE,
                'stripe_customer_id' => [
                    'customer_id'  => $customerId,
                    'name'         => $cardName ?: $keepIfSameCustomer('name'),
                    'card_no'      => $cardNo ?: $keepIfSameCustomer('card_no'),
                    'payment_mode' => $this->environment,
                ],
            ]);
        }

        return [
            'success' => in_array($intent->status, self::INTENT_PAID_STATUSES, true),
            // Not a decline. The issuer wants the cardholder to authenticate -
            // 3-D Secure, which usually means an OTP. The browser finishes it
            // with `client_secret` (stripe.handleNextAction), and the result is
            // read back with retrievePayment() (BUG-066).
            'requires_action' => $intent->status === self::INTENT_REQUIRES_ACTION,
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'client_secret' => $intent->client_secret,
            'customer_id' => $customerId,
            'card_name' => $cardName,
            'card_no' => $cardNo,
            'raw' => $intent->toArray(),
        ];
    }

    /**
     * Charge a saved card off-session (used by the auto-charge cron job).
     * The customer must already have a default payment method attached on Stripe.
     *
     * @param string $customerId   Stripe Customer ID (cus_xxx)
     * @param int    $amountCents  Amount in cents
     * @param string $currency
     * @param string $description
     * @return array
     */
    public function chargeOffSession(
        string $customerId,
        int $amountCents,
        string $currency = Payment::CURRENCY_USD,
        string $description = 'Subscription Auto-Charge',
        ?string $idempotencyReference = null
    ): array {
        $customer = StripeCustomer::retrieve($customerId);
        $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? null;

        if (!$defaultPaymentMethod) {
            $methods = \Stripe\PaymentMethod::all([
                'customer' => $customerId,
                'type' => 'card',
            ]);
            $defaultPaymentMethod = $methods->data[0]->id ?? null;
        }

        $params = [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'description' => $description,
            'confirm' => true,
            'off_session' => true,
        ];

        if ($defaultPaymentMethod) {
            $params['payment_method'] = $defaultPaymentMethod;
        } else {
            $params['automatic_payment_methods'] = [
                'enabled' => true,
                'allow_redirects' => 'never',
            ];
        }

        // Unattended and on a schedule, so this is the path most likely to repeat
        // a charge nobody asked for. A caller that can name the debt gets that
        // protection; one that cannot is left as it was.
        $options = $idempotencyReference === null ? [] : [
            'idempotency_key' => $this->idempotencyKey('offsession', [
                $idempotencyReference,
                $amountCents,
                strtolower($currency),
                $customerId,
            ]),
        ];

        $intent = PaymentIntent::create($params, $options);

        return [
            'success' => $intent->status === 'succeeded',
            'requires_action' => $intent->status === 'requires_action',
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'raw' => $intent->toArray(),
        ];
    }

    public function retrievePayment(string $transactionId): array
    {
        return $this->describeIntent(PaymentIntent::retrieve($transactionId));
    }

    public function cancelPayment(string $transactionId): array
    {
        $intent = PaymentIntent::retrieve($transactionId);

        return $this->describeIntent($intent->cancel());
    }

    /**
     * A PaymentIntent in the shape AuthenticatesCardPayments describes. The
     * client secret is left out: it lets a browser confirm the charge, and
     * nothing that reads an intent back needs it.
     */
    protected function describeIntent(PaymentIntent $intent): array
    {
        $error = $intent->last_payment_error ?? null;

        return [
            'success' => in_array($intent->status, self::INTENT_PAID_STATUSES, true),
            'requires_action' => $intent->status === self::INTENT_REQUIRES_ACTION,
            'processing' => $intent->status === self::INTENT_PROCESSING,
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'error' => $error->message ?? null,
            'error_code' => $error->code ?? null,
            'customer_id' => is_string($intent->customer ?? null) ? $intent->customer : ($intent->customer->id ?? null),
            'raw' => array_diff_key($intent->toArray(), ['client_secret' => true]),
        ];
    }

    public function createCustomer(array $params): array
    {
        $customer = StripeCustomer::create([
            'email' => $params['email'] ?? null,
            'name' => $params['name'] ?? null,
        ]);

        return [
            'success' => true,
            'customer_id' => $customer->id,
            'raw' => $customer->toArray(),
        ];
    }

    public function createSubscription(array $params): array
    {
        // Not used in the application-led model.
        // Billing cycles are managed locally and charged via chargeOffSession().
        throw new RuntimeException("Stripe Subscriptions are not used in this system. Use the application-led billing model instead.");
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        // Not used in the application-led model.
        throw new RuntimeException("Stripe Subscriptions are not used in this system. Subscription cancellation is managed locally.");
    }
}
