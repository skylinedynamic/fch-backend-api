<?php

namespace App\Services\GlobalPayment;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use App\Mail\Payment\PaymentSuccessMail;
use App\Mail\Payment\GlobalPaymentNotificationMail;

use App\Models\Setting\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiErrorException;

class GlobalPaymentService
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
                $payment = Payment::create([
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

                        // Handle Partner specific logic
                        if ($invoice->entity_type === \App\Models\Partner\Partner::class) {
                            $this->handlePartnerPayment($invoice);
                        }
                    }
                }

                // 3. Send Email Notifications
                $borrower = null;
                if (!empty($data['borrower_id'])) {
                    $borrower = Borrower::find($data['borrower_id']);
                    if ($borrower) {
                        $borrower->status = Borrower::STATUS_ACTIVE;
                        $borrower->save();
                    }
                }

                $userEmail = $borrower ? $borrower->email : (Auth::user() ? Auth::user()->email : null);
                $userName = $borrower ? ($borrower->first_name . ' ' . $borrower->last_name) : (Auth::user() ? Auth::user()->name : 'Guest');

                $paymentData = [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'transaction_id' => $payment->transaction_id,
                    'date' => $payment->transaction_date,
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                ];

                // Send to User (Borrower) if valid email exists
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        Mail::to($userEmail)->send(new PaymentSuccessMail($paymentData));
                    } catch (\Exception $e) {
                        Log::error('Failed to send PaymentSuccessMail: ' . $e->getMessage());
                    }
                }

                // Send Global Notification
                // $globalEmail = config('mail.from.address'); // Or a specific setting
                // if ($globalEmail) {
                //     try {
                //         Mail::to($globalEmail)->send(new GlobalPaymentNotificationMail($paymentData));
                //     } catch (\Exception $e) {
                //         Log::error('Failed to send GlobalPaymentNotificationMail: ' . $e->getMessage());
                //     }
                // }
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

    /**
     * Handle Partner Post-Payment Logic
     */
    protected function handlePartnerPayment(Invoice $invoice)
    {
        if ($invoice->entity_type !== \App\Models\Partner\Partner::class) {
            return;
        }

        $partner = \App\Models\Partner\Partner::find($invoice->entity_id);
        if (!$partner) return;

        $subscription = \App\Models\Partner\PartnerSubscription::where('partner_id', $partner->id)->first();
        if (!$subscription) return;

        // Based on business rule: Platform fee starts on the 12th month
        // Update subscription start date or next billing date
        $platformFeeStartDate = now()->addMonths(11); // 12th month effectively

        $subscription->update([
            'status' => 'Active',
            'start_date' => now(), // Enrollment starts now
            'next_billing_date' => $platformFeeStartDate, // Fee triggers in 12th month
        ]);

        Log::info('✅ Partner Subscription Activated', [
            'partner_id' => $partner->id,
            'platform_fee_start' => $platformFeeStartDate
        ]);
    }
}
