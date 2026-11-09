<?php

namespace App\Console\Commands;

use App\Models\Payment\Payment;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settles "Pay Now" card payments left waiting on the payer's bank
 * authentication (3-D Secure, BUG-066).
 *
 * The browser normally settles them through the confirm endpoint. A payer who
 * closes the tab mid-challenge never gets there - though the bank may already
 * have taken the money. So every few minutes each waiting payment is read back
 * from Stripe:
 *
 *  - authenticated: recorded as SUCCEEDED and the invoice paid, so the
 *    auto-charge cron does not charge it a second time;
 *  - refused by the bank: FAILED;
 *  - still waiting after Payment::AUTHENTICATION_TIMEOUT_MINUTES: cancelled at
 *    Stripe and FAILED, which releases the invoice to the auto-charge cron.
 */
class SettleCardAuthentications extends Command
{
    protected $signature = 'payments:settle-card-authentications';

    protected $description = 'Settle card payments left waiting on 3-D Secure bank authentication';

    public function handle(UnifiedPaymentService $payments): int
    {
        $expiredBefore = now()->subMinutes(Payment::AUTHENTICATION_TIMEOUT_MINUTES);
        $settled = 0;

        Payment::awaitingAuthentication()->with('invoice')->orderBy('id')->each(function (Payment $payment) use ($payments, $expiredBefore, &$settled) {
            $cancelReason = $payment->created_at->lt($expiredBefore) ? __('payment.authentication_expired') : null;

            try {
                $result = $payments->settleAwaitingAuthentication($payment, $cancelReason);

                if ($result['status'] !== Payment::STATUS_PENDING) {
                    $settled++;
                }
            } catch (Throwable $e) {
                // Left PENDING for the next pass; one payment never blocks the rest.
                Log::error('[SettleCardAuthentications] Could not settle a payment awaiting authentication', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Payment #{$payment->id} left pending: {$e->getMessage()}");
            }
        });

        $this->info("Settled {$settled} payment(s) awaiting card authentication.");

        return self::SUCCESS;
    }
}
