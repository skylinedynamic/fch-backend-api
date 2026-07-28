<?php

namespace App\Services\GlobalPayment\Stripe;

use App\Models\Partner\SubscriptionPlan;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Product as StripeProduct;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionSchedule as StripeSubscriptionSchedule;

class StripeSubscriptionService
{
    /**
     * Create a Stripe Subscription Schedule based on partner payment selections.
     *
     * @param string $customerId
     * @param array $params
     * @param string $secretKey
     * @return array
     * @throws Exception
     */
    public function createSubscriptionSchedule(string $customerId, array $params, string $secretKey): array
    {
        Stripe::setApiKey($secretKey);

        $productId = $this->getOrCreateSubscriptionProduct($secretKey);

        $enrollmentType = strtolower($params['enrollment_type'] ?? '');
        $isInstallment = $enrollmentType == SubscriptionPlan::INSTALLMENT_PLAN;

        $payableAmount = (float) ($params['payable_amount'] ?? 0.0);
        $downPayment = (float) ($params['down_payment'] ?? 0.0);
        $installmentMonths = (int) ($params['installment_months'] ?? 1);
        $monthlyPlatformFee = (float) ($params['monthly_platform_fee'] ?? 0.0);

        $phases = [];

        if (!$isInstallment) {
            // SCENARIO A: One-Time Payment + Platform Fee
            // Phase 1: 1 month of One-time enrollment fee
            $phases[] = [
                'items' => [
                    [
                        'price_data' => $this->makePriceData($payableAmount, $productId),
                        'quantity' => 1,
                    ]
                ],
                'end_date' => \Carbon\Carbon::now()->addMonth()->timestamp,
            ];
            // Phase 2: Platform fee for 1 month, then release to recurring
            $phases[] = [
                'items' => [
                    [
                        'price_data' => $this->makePriceData($monthlyPlatformFee, $productId),
                        'quantity' => 1,
                    ]
                ],
                'end_date' => \Carbon\Carbon::now()->addMonths(2)->timestamp,
            ];
        } else {
            // Installment-based plans
            if ($downPayment > 0) {
                // SCENARIO B: Installment with Down Payment (e.g. $1,000 down + N months of installments starting next month)
                // Phase 1: Down payment (1 month)
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => $this->makePriceData($downPayment, $productId),
                            'quantity' => 1,
                        ]
                    ],
                    'end_date' => \Carbon\Carbon::now()->addMonth()->timestamp,
                ];

                // Phase 2: Monthly installments
                $remainingBalance = max(0, $payableAmount - $downPayment);
                $monthlyInstallment = $installmentMonths > 0 ? ($remainingBalance / $installmentMonths) : 0;

                $phases[] = [
                    'items' => [
                        [
                            'price_data' => $this->makePriceData($monthlyInstallment, $productId),
                            'quantity' => 1,
                        ]
                    ],
                    'end_date' => \Carbon\Carbon::now()->addMonths(1 + $installmentMonths)->timestamp,
                ];

                // Phase 3: Platform fee (1 month, then release to recurring)
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => $this->makePriceData($monthlyPlatformFee, $productId),
                            'quantity' => 1,
                        ]
                    ],
                    'end_date' => \Carbon\Carbon::now()->addMonths(2 + $installmentMonths)->timestamp,
                ];
            } else {
                // SCENARIO C: Installment without Down Payment (N months of installments starting now)
                // Phase 1: Monthly installments
                $monthlyInstallment = $installmentMonths > 0 ? ($payableAmount / $installmentMonths) : 0;

                $phases[] = [
                    'items' => [
                        [
                            'price_data' => $this->makePriceData($monthlyInstallment, $productId),
                            'quantity' => 1,
                        ]
                    ],
                    'end_date' => \Carbon\Carbon::now()->addMonths($installmentMonths)->timestamp,
                ];

                // Phase 2: Platform fee (1 month, then release to recurring)
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => $this->makePriceData($monthlyPlatformFee, $productId),
                            'quantity' => 1,
                        ]
                    ],
                    'end_date' => \Carbon\Carbon::now()->addMonths(1 + $installmentMonths)->timestamp,
                ];
            }
        }

        Log::info('[StripeSubscriptionService] Creating Subscription Schedule', [
            'customer_id' => $customerId,
            'phases_count' => count($phases),
            'is_installment' => $isInstallment
        ]);

        $schedule = StripeSubscriptionSchedule::create([
            'customer' => $customerId,
            'start_date' => 'now',
            'end_behavior' => 'release', // Release to continue billing the last phase's rate ($399/mo) indefinitely
            'phases' => $phases,
        ]);

        // Retrieve initial invoice's payment intent
        $subscriptionId = $schedule->subscription;
        if (!$subscriptionId) {
            throw new Exception("Stripe Subscription was not created for the schedule.");
        }

        $subscription = null;
        for ($i = 0; $i < 6; $i++) {
            $subscription = StripeSubscription::retrieve([
                'id' => $subscriptionId,
                'expand' => ['latest_invoice.payment_intent']
            ]);

            if (isset($subscription->latest_invoice)) {
                $invoiceId = $subscription->latest_invoice->id;

                // 1. If invoice is draft, finalize it immediately so Stripe attempts payment
                if ($subscription->latest_invoice->status === 'draft') {
                    try {
                        $stripeInvoice = \Stripe\Invoice::retrieve($invoiceId);
                        $stripeInvoice->finalizeInvoice();
                        // Re-retrieve to get the updated status
                        $subscription = StripeSubscription::retrieve([
                            'id' => $subscriptionId,
                            'expand' => ['latest_invoice.payment_intent']
                        ]);
                    } catch (\Exception $e) {
                        Log::debug('[StripeSubscriptionService] Finalization failed: ' . $e->getMessage());
                    }
                }

                // 2. If invoice is finalized (open) but unpaid, trigger pay() to charge the default payment method synchronously
                if (isset($subscription->latest_invoice) && $subscription->latest_invoice->status === 'open') {
                    try {
                        $stripeInvoice = \Stripe\Invoice::retrieve($invoiceId);
                        $stripeInvoice->pay();
                        // Re-retrieve to confirm payment succeeded
                        $subscription = StripeSubscription::retrieve([
                            'id' => $subscriptionId,
                            'expand' => ['latest_invoice.payment_intent']
                        ]);
                    } catch (\Exception $e) {
                        Log::debug('[StripeSubscriptionService] Payment capture failed: ' . $e->getMessage());
                    }
                }

                // If the invoice is successfully paid, we can stop polling
                if (isset($subscription->latest_invoice) && $subscription->latest_invoice->status === 'paid') {
                    break;
                }
            }

            usleep(500000); // Wait 500ms
        }

        return [
            'stripe_subscription_id' => $subscription->id,
            'stripe_schedule_id' => $schedule->id,
            'status' => $subscription->status,
            'client_secret' => $subscription->latest_invoice->payment_intent->client_secret ?? null,
            'invoice_id' => $subscription->latest_invoice->id ?? null,
        ];
    }

    /**
     * Resolve or create the master product for partner usage.
     */
    protected function getOrCreateSubscriptionProduct(string $secretKey): string
    {
        Stripe::setApiKey($secretKey);

        try {
            $products = StripeProduct::all(['limit' => 20]);
            foreach ($products->data as $product) {
                if ($product->name === 'FCH Platform Subscription') {
                    return $product->id;
                }
            }
        } catch (Exception $e) {
            Log::error("[StripeSubscriptionService] Error resolving product list: " . $e->getMessage());
        }

        $product = StripeProduct::create([
            'name' => 'FCH Platform Subscription',
            'description' => 'FCH Partner Platform Enrollment & Recurring Fees',
        ]);

        return $product->id;
    }

    protected function makePriceData(float $amount, string $productId): array
    {
        return [
            'unit_amount' => (int) round($amount * 100),
            'currency' => 'usd',
            'recurring' => [
                'interval' => 'month',
            ],
            'product' => $productId,
        ];
    }
}
