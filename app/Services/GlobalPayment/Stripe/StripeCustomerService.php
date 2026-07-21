<?php

namespace App\Services\GlobalPayment\Stripe;

use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Customer as StripeCustomer;
use Stripe\PaymentMethod as StripePaymentMethod;

class StripeCustomerService
{
    /**
     * Resolve Stripe Customer ID by checking if one is already saved, looking it up by email, or creating a new one.
     *
     * @param string $email
     * @param string $name
     * @param string|null $stripeCustomerId The customer ID if stored locally in our database
     * @return string Stripe Customer ID
     */
    public function resolveCustomer(string $email, string $name, ?string $stripeCustomerId = null): string
    {
        // 1. Use locally stored Stripe Customer ID if available
        if (!empty($stripeCustomerId)) {
            Log::info('[Stripe Customer Service] Using locally stored Stripe customer ID', ['customer_id' => $stripeCustomerId]);
            return $stripeCustomerId;
        }

        // 2. Fallback to searching Stripe by email
        $existing = StripeCustomer::all(['email' => $email, 'limit' => 1]);
        if (!empty($existing->data)) {
            Log::info('[Stripe Customer Service] Found existing Stripe customer by email', ['email' => $email]);
            return $existing->data[0]->id;
        }

        // 3. Create a new Customer if not found
        Log::info('[Stripe Customer Service] Creating new Stripe customer', ['email' => $email]);
        $customer = StripeCustomer::create([
            'email' => $email,
            'name' => $name,
        ]);

        return $customer->id;
    }

    /**
     * Attach a payment method to a customer and set it as the default for invoices.
     *
     * @param string $customerId
     * @param string $paymentMethodId
     * @return void
     * @throws Exception
     */
    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        try {
            $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);
            
            if ($paymentMethod->customer !== $customerId) {
                $paymentMethod->attach(['customer' => $customerId]);
            }

            StripeCustomer::update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            Log::info('[Stripe Customer Service] Default payment method updated', [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId
            ]);
        } catch (Exception $e) {
            Log::error('[Stripe Customer Service] Failed to bind payment method', [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update customer email in Stripe when it changes locally in the application.
     *
     * @param string $customerId
     * @param string $newEmail
     * @return void
     * @throws Exception
     */
    public function updateCustomerEmail(string $customerId, string $newEmail): void
    {
        try {
            StripeCustomer::update($customerId, [
                'email' => $newEmail
            ]);
            Log::info('[Stripe Customer Service] Updated customer email on Stripe', [
                'customer_id' => $customerId,
                'new_email' => $newEmail
            ]);
        } catch (Exception $e) {
            Log::error('[Stripe Customer Service] Failed to update customer email on Stripe', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
