<?php

namespace App\Services\GlobalPayment;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Setting\Setting;
use App\Models\Borrower\Borrower;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiErrorException;
use App\Mail\Payment\PaymentSuccessMail;
use App\Services\FCH\Subscription\SubscriptionService;

class GlobalPaymentService
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

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

        $entityTypeInput  = $data['entity_type'] ?? null;
        $entityId = $data['entity_id'] ?? null;

        $map = [
            'borrower' => Borrower::class,
            Borrower::class => Borrower::class,

            'partner' => Partner::class,
            Partner::class => Partner::class,
        ];

        $entityType = null;

        if ($entityTypeInput) {
            $key = strtolower(trim($entityTypeInput));
            $entityType = $map[$key] ?? $map[$entityTypeInput] ?? null;
        }


        if (!$entityType) {
            if ($entityType == Borrower::class) {
                $entityType = Borrower::class;
                $entityId = $data['borrower_id'];
            } elseif ($entityType == Partner::class) {
                $entityType = Partner::class;
                $entityId = Auth::user()->entity_id;
                Log::info('Entity: ' . $entityId);
            }
        }
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
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'borrower_id' => $data['borrower_id'] ?? null,
                    'partner_id' => $data['partner_id'] ?? null,
                    'invoice_id' => $data['invoice_id'] ?? null,
                ],
            ]);

            $invoice = null;
            if (!empty($data['invoice_id'])) {
                $invoice = Invoice::where('id', $data['invoice_id'])->first();
            }

            $payment = null;
            if (in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {

                $borrowerId = null;
                if ($invoice->entity_type == Borrower::class) {
                    $borrowerId = $invoice->entity_id;
                }

                $payment = Payment::create([
                    'partner_id' => $data['partner_id'] ?? Auth::user()?->partner_id,
                    'borrower_id' => $borrowerId,
                    'invoice_id' => $invoice ? $invoice->invoice_id : null,
                    'entity_type' => $invoice->entity_type,
                    'entity_id' => $invoice->entity_id,
                    'transaction_id' => $paymentIntent->id,
                    'payment_from' => 'Stripe',
                    'payment_type' => 'Card',
                    'amount' => $data['amount'] / 100,
                    'currency' => $paymentIntent->currency,
                    'is_partner_paying' => $data['is_partner_paying'] ?? false,
                    'details' => $paymentIntent->toArray(),
                    'status' => $paymentIntent->status,
                    'transaction_date' => now(),
                    'created_by' => Auth::id(),
                ]);

                if ($invoice) {
                    $invoice->update([
                        'status' => Invoice::STATUS_PAID,
                        'payment_date' => now(),
                    ]);

                    $this->subscriptionService->handleSuccessfulPayment($invoice);

                    if ($invoice->entity_type === Partner::class) {
                        $this->handlePartnerPayment($invoice);
                    } else if ($invoice->entity_type === Borrower::class) {
                        $this->handleBorrowerPayment($invoice);
                    }
                }

                $borrower = null;
                if ($invoice->entity_type === Borrower::class && $invoice->entity_id) {
                    $borrower = Borrower::find($invoice->entity_id);
                } elseif (!empty($data['borrower_id'])) {
                    $borrower = Borrower::find($data['borrower_id']);
                }

                if ($borrower && $borrower->status !== Borrower::STATUS_ACTIVE) {
                    $borrower->update(['status' => Borrower::STATUS_ACTIVE]);
                }

                $userEmail = $borrower ? $borrower->email : (Auth::user() ? Auth::user()->email : null);
                $userName = $borrower ? ($borrower->first_name . ' ' . $borrower->last_name) : (Auth::user() ? Auth::user()->name : 'Guest');

                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $paymentData = [
                            'amount' => $payment->amount,
                            'currency' => $payment->currency,
                            'transaction_id' => $payment->transaction_id,
                            'description' => $paymentIntent->description ?? 'Four Corner Holdings Program Payment',
                            'date' => $payment->transaction_date,
                            'user_name' => $userName,
                            'user_email' => $userEmail,
                        ];
                        Mail::to($userEmail)->send(new PaymentSuccessMail($paymentData));
                    } catch (\Exception $e) {
                        Log::error('Failed to send PaymentSuccessMail: ' . $e->getMessage());
                    }
                }
            }

            DB::commit();
            return $payment ?? $paymentIntent;
        } catch (ApiErrorException $e) {
            DB::rollBack();
            $this->recordFailedPayment($data, $e->getMessage(), 'Stripe Api Error');
            Log::error('Stripe Payment Error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->recordFailedPayment($data, $e->getMessage(), 'System Error');
            Log::error('Payment Processing Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Record a failed payment.
     */
    private function recordFailedPayment(array $data, string $errorMessage, string $errorType): void
    {
        try {
            $invoice = null;
            if (!empty($data['invoice_id'])) {
                $invoice = Invoice::where('id', $data['invoice_id'])->first();
            }

            Payment::create([
                'partner_id' => $data['partner_id'] ?? Auth::user()?->partner_id,
                'borrower_id' => $invoice->borrower_id ?? null,
                'invoice_id' => $invoice ? $invoice->invoice_id : null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'transaction_id' => 'FAILED_' . uniqid(),
                'payment_from' => 'Stripe',
                'payment_type' => 'Card',
                'amount' => ($data['amount'] ?? 0) / 100,
                'currency' => $data['currency'] ?? 'usd',
                'details' => [
                    'error' => $errorMessage,
                    'type' => $errorType,
                    'input_data' => $data
                ],
                'status' => 'failed',
                'transaction_date' => now(),
                'created_by' => Auth::id(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record failed payment: ' . $e->getMessage());
        }
    }

    /**
     * Handle Partner Post-Payment Logic (Legacy/Status updates)
     */
    protected function handlePartnerPayment(Invoice $invoice)
    {
        if ($invoice->entity_type !== \App\Models\Partner\Partner::class) {
            return;
        }

        $partner = Partner::find($invoice->entity_id);
        if (!$partner) return;

        $partner->update([
            'status' => Partner::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        Log::info('Partner Status Activated via Payment', ['partner_id' => $partner->id]);
    }

    /**
     * Handle Borrower Post-Payment Logic (Legacy/Status updates)
     */
    protected function handleBorrowerPayment(Invoice $invoice)
    {
        if ($invoice->entity_type !== Borrower::class) {
            return;
        }

        $borrower = Borrower::find($invoice->entity_id);
        if (!$borrower) return;

        $borrower->update([
            'status' => Borrower::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        Log::info('Borrower Status Activated via Payment', ['borrower_id' => $borrower->id]);
    }
}
