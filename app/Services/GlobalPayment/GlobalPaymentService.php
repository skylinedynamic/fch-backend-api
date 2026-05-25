<?php

namespace App\Services\GlobalPayment;

use \App\Services\FCH\Common\Borrower\BorrowerService;
use App\Mail\Payment\PaymentSuccessMail;
use App\Mail\Payment\PaymentSuccessUserMail;
use App\Mail\Payment\PaymentFailedMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;

use App\Models\Setting\PaymentMethod;
use App\Models\User\User;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Services\FCH\InvoiceService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Mail;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class GlobalPaymentService
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
        protected EmailConfigurationService $emailConfigService,
        protected PaymentMethodService $paymentMethodService,
        protected Payment $payment
    ) {
    }

    /**
     * Resolve the target partner ID dynamically from inputs and context.
     */
    private function resolvePartnerId(array $data): ?int
    {
        $partnerId = null;

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::where('id', $data['invoice_id'])->first();
            if ($invoice) {
                // If it is a partner invoice (partner paying the host platform fee/enrollment fee),
                // configure Stripe for the Host because the host receives the payment.
                if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
                    $partnerId = Partner::where('is_host', true)->first()?->id;
                } else {
                    $partnerId = $invoice->partner_id;
                }
            }
        }

        if (!$partnerId && Auth::check()) {
            if (in_array(Auth::user()->user_type, [User::PARTNER, User::ADMIN])) {
                $partnerId = Partner::where('is_host', true)->first()?->id;
            } else {
                $partnerId = Auth::user()->partner_id;
            }
        }

        if (!$partnerId) {
            $partnerId = Partner::where('is_host', true)->first()?->id;
        }

        return $partnerId;
    }

    /**
     * Create and process a Stripe payment.
     */
    public function processPayment(array $data)
    {
        $partnerId = $this->resolvePartnerId($data);
        $setting = $this->payment->configureStripeForPartner($partnerId);
        $invoice = !empty($data['invoice_id']) ? Invoice::where('id', $data['invoice_id'])->first() : null;

        $entityTypeInput = $data['entity_type'] ?? null;
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
            if (!empty($data['borrower_id'])) {
                $entityType = Borrower::class;
                $entityId = $data['borrower_id'];
            } elseif (Auth::check()) {
                $entityType = Partner::class;
                $entityId = Auth::user()->entity_id ?? Auth::user()->partner_id;
                Log::info('Entity: ' . $entityId);
            }
        }

        DB::beginTransaction();
        try {
            // If it's a partner paying, we MUST attach a Stripe Customer and save the card so AutoCharge works later
            $stripeCustomerId = null;
            $setupFutureUsage = null;
            
            if ($entityType === Partner::class && $entityId) {
                $partnerToSave = Partner::find($entityId);
                if ($partnerToSave && $partnerToSave->email) {
                    // Try to find existing Stripe customer by email
                    $existingCustomers = \Stripe\Customer::all(['email' => $partnerToSave->email, 'limit' => 1]);
                    if (!empty($existingCustomers->data)) {
                        $stripeCustomerId = $existingCustomers->data[0]->id;
                    } else {
                        // Create a new Stripe customer
                        $newCustomer = \Stripe\Customer::create([
                            'email' => $partnerToSave->email,
                            'name' => $partnerToSave->company_name ?? $partnerToSave->full_name,
                        ]);
                        $stripeCustomerId = $newCustomer->id;
                    }
                    $setupFutureUsage = 'off_session'; // Save the card for future auto-charges
                }
            }

            $paymentIntentData = [
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
            ];

            if ($stripeCustomerId) {
                $paymentIntentData['customer'] = $stripeCustomerId;
                $paymentIntentData['setup_future_usage'] = $setupFutureUsage;
            }

            $paymentIntent = PaymentIntent::create($paymentIntentData);

            // Re-fetch invoice inside transaction if not already loaded
            if (!$invoice && !empty($data['invoice_id'])) {
                $invoice = Invoice::where('id', $data['invoice_id'])->first();
            }

            $payment = null;
            if (in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {

                $borrowerId = null;
                if ($invoice && $invoice->entity_type == Borrower::class) {
                    $borrowerId = $invoice->entity_id;
                }

                $payment = $this->payment->createRecord([
                    'partner_id' => $invoice ? $invoice->partner_id : (Auth::user()?->partner_id ?? $partnerId),
                    'borrower_id' => $borrowerId,
                    'invoice_id' => $invoice ? $data['invoice_id'] : null,
                    'entity_type' => $invoice ? $invoice->entity_type : null,
                    'entity_id' => $invoice ? $invoice->entity_id : null,
                    'transaction_id' => $paymentIntent->id,
                    'amount' => $data['amount'] / 100,
                    'currency' => strtoupper($paymentIntent->currency),
                    'is_partner_paying' => $data['is_partner_paying'] ?? false,
                    'details' => $paymentIntent->toArray(),
                    'status' => $paymentIntent->status,
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

                        // Charge the platform fee if the partner is a White-Label or Certified Partner
                        if ($invoice->partner_id && in_array($invoice->partner?->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED]) && !$invoice->is_partner_paying) {
                            $settings = $this->paymentMethodService->getSettings($invoice->partner_id);
                            if ($settings->partner_auto_charge_host_fee) {
                                Log::info('[AutoCharge] Charging platform fee for partner', [
                                    'invoice' => $invoice,
                                    'partner' => $invoice->partner,
                                    'payment' => $payment,
                                ]);
                                $this->chargePlatformFee($invoice, $invoice->partner, $payment);
                            }
                        }
                    }
                }

                // Log Activity Event
                $this->activityLogService->logModelEvent(
                    $payment,
                    ($invoice && $invoice->entity_type === Partner::class) ? ActivityLog::LOG_TYPE_PARTNER_PAYMENT : ActivityLog::LOG_TYPE_BORROWER_PAYMENT,
                    [
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency
                    ]
                );

                $borrower = null;
                if ($invoice && $invoice->entity_type === Borrower::class && $invoice->entity_id) {
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
                    $paymentData = [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'transaction_id' => $payment->transaction_id,
                        'description' => $paymentIntent->description ?? config('app.name') . ' Payment',
                        'date' => $payment->transaction_date,
                        'user_name' => $userName,
                        'user_email' => $userEmail,
                    ];
                    $partnerIdForMail = $invoice ? $invoice->partner_id : ($data['partner_id'] ?? null);
                    // $restore = $this->emailConfigService->applyToMailer($partnerIdForMail);
                    try {
                        if ($invoice && $invoice->is_partner_paying) {
                            Mail::to(Auth::user()->email)->send(new PaymentSuccessMail($paymentData, $partnerIdForMail));
                        } else {
                            // 1. Send to Borrower
                            Mail::to($userEmail)->send(new PaymentSuccessMail($paymentData, $partnerIdForMail));

                            if ($invoice && $invoice->partner && $invoice->partner->email) {
                                Mail::to($invoice->partner->email)->send(new PaymentSuccessMail($paymentData, $partnerIdForMail));
                            }

                            $settings = $this->paymentMethodService->getSettings($invoice->partner_id ?? null);
                            if (!empty($settings->partner_auto_charge_host_fee)) {
                                $host = Partner::where('is_host', true)->first();
                                
                                // 3. Send to Host
                                if ($host && $host->email) {
                                    Mail::to($host->email)->send(new PaymentSuccessMail($paymentData, $host->id));
                                }

                                // 4. Send to Partner again for auto charge notification
                                if ($invoice && $invoice->partner && $invoice->partner->email) {
                                    Mail::to($invoice->partner->email)->send(new PaymentSuccessMail($paymentData, $host->id ?? null));
                                }
                            }
                        }
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
            $partnerId = $this->resolvePartnerId($data);
            $invoice = !empty($data['invoice_id']) ? Invoice::where('id', $data['invoice_id'])->first() : null;

            $this->payment->createRecord([
                'partner_id' => $partnerId,
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
     * Handle Partner Post-Payment Logic (Legacy/Status updates)
     */
    protected function handlePartnerPayment(Invoice $invoice)
    {
        if ($invoice->entity_type !== \App\Models\Partner\Partner::class) {
            return;
        }

        $partner = Partner::find($invoice->entity_id);
        if (!$partner)
            return;

        $partner->update([
            'status' => Partner::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);
        if($invoice->borrower_id) {
            $borrower = Borrower::find($invoice->borrower_id);
            $borrower->update([
                'partner_payment_for_me' => true,
                'updated_at' => now(),
            ]);
        }

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
        if (!$borrower)
            return;

        $borrower->update([
            'status' => Borrower::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        Log::info('Borrower Status Activated via Payment', ['borrower_id' => $borrower->id]);
    }

    /**
     * Automatically charge the White-Label or Certified Partner's enrollment fee.
     */
    protected function chargePlatformFee(Invoice $invoice, Partner $partner, Payment $payment): void
    {
        // CONDITION 1: Check if the borrower belongs to a White-Label or Certified Partner.
        // If so, look for their unpaid partner invoice (with borrower_id as null).
        // If found, automatically charge the Partner's card.
        try {
            $borrower = Borrower::find($invoice->entity_id);
            if ($borrower && !$borrower->partner_payment_for_me) {
                $partnerInvoice = Invoice::where('partner_id', $partner->id)
                    ->where('entity_type', Partner::class)
                    ->where('borrower_id', $borrower->id)
                    ->first();

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
            'amount' => $amount * 100, // recordFailedPayment divides by 100
            'currency' => 'usd',
        ];

        try {
            // ── Step 1: Find a successful payment from this partner to get their customer and payment method
            $partnerPayments = $this->payment->where('entity_type', Partner::class)
                ->where('entity_id', $partner->id)
                ->where('status', 'succeeded')
                ->where('payment_from', Payment::PAYMENT_FROM_STRIPE)
                ->orderBy('id', 'desc')
                ->get();

            Log::info('[AutoCharge] Found partner payments', [
                'partnerPayments' => $partnerPayments,
            ]);

            $partnerPayment = null;
            foreach ($partnerPayments as $p) {
                if (!empty($p->details['customer']) && !empty($p->details['payment_method'])) {
                    $partnerPayment = $p;

                    Log::info('[AutoCharge] Customer and payment method found', [
                        'customer' => $p->details['customer'],
                        'payment_method' => $p->details['payment_method'],
                    ]);

                    break;
                }
            }

            if (!$partnerPayment) {
                Log::warning('[AutoCharge] Could not find saved Stripe payment method for partner ' . $partner->id);
                $this->recordFailedPayment($failedData, 'Could not find saved Stripe payment method for partner', 'AutoCharge Missing Card');
                return false;
            }

            $customerId = $partnerPayment->details['customer'];
            $paymentMethodId = $partnerPayment->details['payment_method'];

            Log::info('[AutoCharge] Partner payment located', [
                'payment_id' => $partnerPayment->id,
                'customer_id' => $customerId,
                'payment_method' => $paymentMethodId,
            ]);

            // ── Step 2: Fetch the Host's Stripe credentials (because partner's card is on the Host's Stripe account)
            $host = Partner::where('is_host', true)->first();
            if (!$host) {
                Log::error('[AutoCharge] Host partner not found for automatic billing.');
                $this->recordFailedPayment($failedData, 'Host partner not found for automatic billing.', 'AutoCharge Config Error');
                return false;
            }

            $setting = $this->payment->configureStripeForPartner($host->id);

            Log::info('[AutoCharge] Host Stripe key set for charging partner', [
                'host_id' => $host->id,
                'mode' => $setting->payment_mode,
                'setting' => $setting,
            ]);

            // ── Step 3: Create off-session PaymentIntent on the Host's Stripe account
            $paymentIntent = PaymentIntent::create([
                'amount' => (int) round($amount * 100), // e.g. 800.00 → 80000 cents
                'currency' => 'usd',
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

            if (!in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {
                Log::error('[AutoCharge] PaymentIntent did not succeed', [
                    'pi_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                ]);
                $this->recordFailedPayment($failedData, 'PaymentIntent failed with status: ' . $paymentIntent->status, 'Stripe Payment Failed');
                return false;
            }

            // ── Step 5: Mark the partner invoice as paid
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

            $payment = $this->payment->createRecord([
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
                'created_by' => Auth::id() ?? $partner->users()->first()?->id,
            ]);

            // ── Step 7: Log activity
            $this->activityLogService->logModelEvent(
                $payment,
                ActivityLog::LOG_TYPE_PARTNER_PAYMENT,
                [
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ]
            );

            Log::info('[AutoCharge] SUCCESS', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'invoice_id' => $invoice->id,
                'partner_id' => $partner->id,
                'amount' => $payment->amount,
            ]);

            // Ensure the borrower's frontend refreshes their user state accurately
            if ($borrower) {
                User::where('entity_id', $borrower->id)
                    ->where('entity_type', Borrower::class)
                    ->update(['is_refresh' => 1]);
            }

            // ── Step 8: Send Emails
            $paymentData = [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'transaction_id' => $payment->transaction_id,
                'description' => 'Partner Platform Fee Auto-Charge',
                'date' => $payment->transaction_date,
                'user_name' => $partner->business_name ?: $partner->first_name . ' ' . $partner->last_name,
                'user_email' => $partner->email,
            ];

            try {
                $host = Partner::where('is_host', true)->first();
                if ($host && $host->email) {
                    Mail::to($host->email)->send(new PaymentSuccessMail($paymentData, $host->id));
                }
                if ($partner->email) {
                    Mail::to($partner->email)->send(new PaymentSuccessMail($paymentData, clone $host ? $host->id : null));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send AutoCharge Success Mail: ' . $e->getMessage());
            }

            return true;

        } catch (\Stripe\Exception\CardException $e) {
            // Card was declined — log details, do NOT rethrow (non-fatal)
            Log::error('[AutoCharge] Card declined', [
                'partner_id' => $partner->id,
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);
            $this->recordFailedPayment($failedData, $e->getMessage(), 'Card Declined');
            
            // Send Failed Mail
            $this->sendAutoChargeFailedEmail($partner, $amount, $invoice->id, $e->getMessage());
            
            return false;

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // "No such customer" = wrong Stripe account key used
            Log::error('[AutoCharge] Stripe invalid request — likely wrong Stripe account key', [
                'partner_id' => $partner->id,
                'error' => $e->getMessage(),
                'hint' => 'Ensure partner Stripe key is used, not host key.',
            ]);
            $this->recordFailedPayment($failedData, $e->getMessage(), 'Stripe Invalid Request');
            
            $this->sendAutoChargeFailedEmail($partner, $amount, $invoice->id, $e->getMessage());

            return false;

        } catch (\Stripe\Exception\AuthenticationException $e) {
            Log::error('[AutoCharge] Stripe authentication failed — check partner secret key', [
                'partner_id' => $partner->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordFailedPayment($failedData, $e->getMessage(), 'Stripe Authentication Error');
            
            $this->sendAutoChargeFailedEmail($partner, $amount, $invoice->id, $e->getMessage());

            return false;

        } catch (\Exception $e) {
            Log::error('[AutoCharge] Unexpected exception', [
                'partner_id' => $partner->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordFailedPayment($failedData, $e->getMessage(), 'System Error');
            
            $this->sendAutoChargeFailedEmail($partner, $amount, $invoice->id, $e->getMessage());

            return false;
        }
    }

    /**
     * Helper to send auto-charge failed email.
     */
    protected function sendAutoChargeFailedEmail(Partner $partner, float $amount, $invoiceId, string $errorMessage): void
    {
        $paymentData = [
            'amount' => $amount,
            'currency' => Payment::CURRENCY_USD,
            'transaction_id' => 'FAILED-' . uniqid(),
            'description' => 'Partner Platform Fee Auto-Charge (' . $errorMessage . ')',
            'date' => now(),
            'user_name' => $partner->business_name ?: $partner->first_name . ' ' . $partner->last_name,
            'user_email' => $partner->email,
        ];

        try {
            $host = Partner::where('is_host', true)->first();
            if ($host && $host->email) {
                Mail::to($host->email)->send(new PaymentFailedMail($paymentData, $host->id));
            }
            if ($partner->email) {
                Mail::to($partner->email)->send(new PaymentFailedMail($paymentData, clone $host ? $host->id : null));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send AutoCharge Failed Mail: ' . $e->getMessage());
        }
    }

    /**
     * Create and process a Stripe payment for users (Seat Purchase).
     */
    public function processPaymentForUsers(array $data)
    {
        $partnerId = $data['partner_id'] ?? null;
        $partner = Partner::find($partnerId);

        if (!$partner) {
            throw new \Exception('Partner not found for seat purchase.');
        }

        // Configure Stripe for the Host since seat payments go to the Host
        $host = Partner::where('is_host', true)->first();
        if (!$host) {
            throw new \Exception('Host partner not found.');
        }
        $setting = $this->payment->configureStripeForPartner($host->id);

        DB::beginTransaction();
        try {
            // Find or create Customer on Host Stripe account
            $customers = Customer::all(['email' => $partner->email, 'limit' => 1]);
            if (!empty($customers->data)) {
                $customerId = $customers->data[0]->id;
            } else {
                $stripeCustomer = Customer::create([
                    'email' => $partner->email,
                    'name' => $partner->company_name ?? $partner->full_name,
                ]);
                $customerId = $stripeCustomer->id;
            }

            // 1. Create Stripe PaymentIntent on Host account
            $paymentIntent = PaymentIntent::create([
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'usd',
                'customer' => $customerId,
                'payment_method' => $data['payment_method_id'],
                'setup_future_usage' => 'off_session',
                'description' => $data['description'] ?? '',
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'partner_id' => $partnerId,
                    'quantity' => $data['quantity'] ?? 1,
                    'entity_type' => $data['entity_type'],
                    'entity_id' => $data['entity_id'] ?? null,
                ],
            ]);

            if (in_array($paymentIntent->status, ['succeeded', 'requires_capture'])) {
                $invoice = Invoice::where('id', $data['invoice_id'])->first();
                $invoice->update([
                    'payment_date' => now(),
                    'status' => Invoice::STATUS_PAID,
                    'currency' => strtoupper($paymentIntent->currency),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // 3. Create Payment
                $payment = $this->payment->createRecord([
                    'partner_id' => $partner->id,
                    'invoice_id' => $data['invoice_id'],
                    'entity_type' => Partner::class,
                    'entity_id' => $partner->id,
                    'transaction_id' => $paymentIntent->id,
                    'amount' => $data['amount'] / 100,
                    'currency' => strtoupper($paymentIntent->currency),
                    'details' => $paymentIntent->toArray(),
                    'status' => 'succeeded',
                ]);

                // Log Activity Event
                $this->activityLogService->logModelEvent(
                    $payment,
                    ActivityLog::LOG_TYPE_PARTNER_PAYMENT,
                    [
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'quantity' => 1,
                    ],
                    'User Purchase Successful'
                );

                // 4. Update Partner Paid User Count
                $partner->update([
                    'total_paid_users' => $partner->total_paid_users + 1,
                ]);

                // 5. Update User Status and Password
                $user = User::where('id', $data['user_id'])->where('partner_id', $partner->id)->first();

                // If not found by ID, try finding by entity reference (Employee/Borrower)
                if (!$user && !empty($data['entity_id']) && !empty($data['entity_type'])) {
                    $user = User::where('entity_id', $data['entity_id'])
                        ->where('entity_type', $data['entity_type'])
                        ->where('partner_id', $partner->id)
                        ->first();
                }

                $plainPassword = null;
                if ($user) {
                    if ($user->payment_status == User::PAYMENT_STATUS_PAID && $user->start_date && $user->end_date) {
                        $duration = $user->start_date->diffInDays($user->end_date);
                        $newStart = $user->end_date->copy()->addDay();
                        $newEnd = $newStart->copy()->addDays($duration);
                        $user->start_date = $newStart;
                        $user->end_date = $newEnd;
                    }

                    $plainPassword = bin2hex(random_bytes(4)); // 8 chars
                    $user->update([
                        'status' => User::STATUS['active'],
                        'payment_status' => User::PAYMENT_STATUS_PAID,
                        'password' => Hash::make($plainPassword),
                        'start_date' => $user->start_date,
                        'end_date' => $user->end_date,
                    ]);
                    Log::info('User activated and password set', ['user_id' => $user->id]);
                } else {
                    Log::warning('User not found for seat purchase activation', [
                        'user_id' => $data['user_id'] ?? null,
                        'entity_id' => $data['entity_id'] ?? null,
                        'partner_id' => $partner->id
                    ]);
                }

                // 6. Send Email
                if ($user) {
                    $paymentData = [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'transaction_id' => $payment->transaction_id,
                        'description' => $paymentIntent->description,
                        'date' => $payment->transaction_date,
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'plainPassword' => $plainPassword,
                        'user' => $user,
                    ];
                    try {
                        Mail::to($user->email)->send(new PaymentSuccessUserMail($paymentData, $partner->id));
                    } catch (\Exception $e) {
                        Log::error('Failed to send Seat Purchase Mail: ' . $e->getMessage());
                    }
                }

                DB::commit();
                return $payment;
            }

            throw new \Exception('Payment status: ' . $paymentIntent->status);
        } catch (ApiErrorException $e) {
            DB::rollBack();
            $this->recordFailedPayment($data, $e->getMessage(), 'Stripe Api Error');
            Log::error('Stripe Seat Purchase Error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->recordFailedPayment($data, $e->getMessage(), 'System Error');
            Log::error('Seat Purchase Processing Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
