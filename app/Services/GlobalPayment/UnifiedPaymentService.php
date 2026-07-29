<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use App\Services\FCH\Subscription\SubscriptionService;
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
        protected PaymentGatewayManager $gatewayManager,
        protected Payment $paymentRecord,
        protected SubscriptionService $subscriptionService
    ) {
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

        if ($invoice->entity_type !== $user->entity_type || (int) $invoice->entity_id !== (int) $user->entity_id) {
            throw new Exception("Unauthorized invoice payment attempt.");
        }

        $partnerId      = $this->resolvePartnerId($invoice);
        $receiverId     = $invoice->entity_type == Partner::class ? Partner::host()->id : $invoice->partner_id;
        $partnerProvider = $this->gatewayManager->resolvePartnerProvider($receiverId, $data['payment_method_id'] ?? null);
        $driver         = $this->gatewayManager->driverFor($partnerProvider);
        $providerName   = $partnerProvider->provider?->name;

        $borrowerId = $invoice->entity_type === Borrower::class ? $invoice->entity_id : $invoice->borrower_id;

        DB::beginTransaction();
        try {
            // 1. Charge the payment gateway
            $gatewayResult = $driver->charge([
                'amount'           => $invoice->payment_amount,
                'currency'         => $invoice->currency ?? 'USD',
                'payment_method_id' => $data['payment_method_id'],
                'payment_token'    => $data['payment_token'] ?? null,
                'invoice_id'       => $invoice->id,
                'description'      => "Payment for invoice #{$invoice->invoice_id}",
            ]);

            if (empty($gatewayResult['success'])) {
                throw new Exception("Payment charge failed via " . ($providerName ?? 'gateway'));
            }

            // 2. Create local payment ledger entry
            $payment = $this->paymentRecord->createRecord([
                'partner_id'     => $partnerId,
                'borrower_id'    => $borrowerId,
                'invoice_id'     => $invoice->id,
                'entity_type'    => $invoice->entity_type,
                'entity_id'      => $invoice->entity_id,
                'transaction_id' => $gatewayResult['transaction_id'] ?? ('TX_' . uniqid()),
                'amount'         => $invoice->payment_amount,
                'currency'       => strtoupper($invoice->currency ?? 'USD'),
                'payment_from'   => $providerName ?? 'Default',
                'status'         => Payment::STATUS_SUCCEEDED,
                'details'        => $gatewayResult['raw'] ?? [],
            ]);

            // 3. Mark the invoice as Paid
            $invoice->update([
                'status'       => Invoice::STATUS_PAID,
                'payment_date' => now(),
            ]);

            // 4. Advance the local subscription phase and schedule next billing date
            //    (Application-Led Model: Laravel is the source of truth for billing cycles)
            $this->subscriptionService->handleSuccessfulPayment($invoice);

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
     * Resolve target partner ID from the invoice's payer.
     */
    protected function resolvePartnerId(Invoice $invoice): ?int
    {
        if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
            return Partner::where('is_host', true)->first()?->id;
        }

        return $invoice->partner_id;
    }
}
