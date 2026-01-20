<?php

namespace App\Services\Global\Payment;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;

use App\Models\Setting\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Stripe\Exception\ApiErrorException;

class PaymentService
{
    public function __construct() {}

    /**
     * Create and process a Stripe payment.
     */
    public function processPayment(array $data)
    {
        $setting = Setting::where('status', true)->first();

        if (!$setting) {
            throw new \Exception('Payment settings not found or inactive.');
        }

        $secretKey = ($setting->payment_mode === 'production')
            ? $setting->stripe_secret_production
            : $setting->stripe_secret_sandbox;

        if (!$secretKey) {
            throw new \Exception('Stripe secret key not configured in settings for ' . $setting->payment_mode . ' mode.');
        }

        Stripe::setApiKey($secretKey);

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

            if (in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {
                Payment::create([
                    'partner_id' => $data['partner_id'] ?? Auth::user()?->partner_id,
                    'borrower_id' => $data['borrower_id'] ?? null,
                    'entity_type' => $data['entity_type'] ?? null,
                    'entity_id' => $data['entity_id'] ?? null,
                    'transaction_id' => $paymentIntent->id,
                    'payment_from' => 'Stripe',
                    'payment_type' => 'Card',
                    'amount' => $data['amount'] / 100,
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'transaction_date' => now(),
                    'created_by' => Auth::id(),
                ]);

                // 2. Update Invoice if provided
                if (!empty($data['invoice_id'])) {
                    $invoice = Invoice::where('id', $data['invoice_id'])
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
