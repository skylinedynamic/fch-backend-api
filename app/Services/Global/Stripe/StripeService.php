<?php

namespace App\Services\Global\Stripe;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;
use Illuminate\Support\Facades\Log;

use App\Models\Payment\Payment;
use App\Models\Invoice\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('stripe.secret_key'));
    }

    /**
     * Create and process a Stripe payment.
     */
    public function processPayment(array $data)
    {
        DB::beginTransaction();
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? config('stripe.currency', 'usd'),
                'payment_method' => $data['payment_method_id'],
                'description' => $data['description'] ?? 'Stripe Payment',
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'entity_id' => $data['entity_id'] ?? null,
                    'entity_type' => $data['entity_type'] ?? null,
                    'borrower_id' => $data['borrower_id'] ?? null,
                    'partner_id' => $data['partner_id'] ?? null,
                    'invoice_id' => $data['invoice_id'] ?? null,
                ],
            ]);

            // If payment is successful (succeeded or requires_capture)
            if (in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {
                // 1. Record Payment
                Payment::create([
                    'partner_id' => $data['partner_id'] ?? Auth::user()?->partner_id,
                    'borrower_id' => $data['borrower_id'] ?? null,
                    'entity_type' => $data['entity_type'] ?? null,
                    'entity_id' => $data['entity_id'] ?? null,
                    'transaction_id' => $paymentIntent->id,
                    'amount' => $data['amount'] / 100, // Convert cents to dollars if needed, or keep cents? Usually UI sends cents for Stripe.
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'transaction_date' => now(),
                    'created_by' => Auth::id(),
                ]);

                // 2. Update Invoice if provided
                if (!empty($data['invoice_id'])) {
                    $invoice = Invoice::where('id', $data['invoice_id'])
                        ->orWhere('invoice_id', $data['invoice_id'])
                        ->first();

                    if ($invoice) {
                        $invoice->update([
                            'status' => Invoice::STATUS_PAID,
                            'payment_date' => now(),
                        ]);
                    }
                }
            }

            DB::commit();
            return $paymentIntent;
        } catch (ApiErrorException $e) {
            DB::rollBack();
            Log::error('❌ Stripe Payment Error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Payment Processing Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
