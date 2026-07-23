<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class UnifiedPaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected Payment $paymentRecord
    ) {
    }

    /**
     * Process a one-time payment against an invoice via the unified gateway driver architecture.
     * Amount, currency, description, and payee details are all derived from the invoice itself.
     *
     * @param array $data ['provider_id' => ?int, 'invoice_id' => int, 'payment_method_id' => string]
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

        $partnerId = $this->resolvePartnerId($invoice);
        $partnerProvider = $this->gatewayManager->resolvePartnerProvider($partnerId, $data['provider_id'] ?? null);
        $driver = $this->gatewayManager->driverFor($partnerProvider);
        $providerName = $partnerProvider->provider?->name;

        $borrowerId = $invoice->entity_type === Borrower::class ? $invoice->entity_id : $invoice->borrower_id;

        DB::beginTransaction();
        try {
            $gatewayResult = $driver->charge([
                'amount' => $invoice->payment_amount,
                'currency' => $invoice->currency ?? 'USD',
                'payment_method_id' => $data['payment_method_id'],
                'description' => "Payment for invoice #{$invoice->invoice_id}",
            ]);

            if (empty($gatewayResult['success'])) {
                throw new Exception("Payment charge failed via " . ($providerName ?? 'gateway'));
            }

            // Create local payment ledger entry
            $payment = $this->paymentRecord->createRecord([
                'partner_id' => $partnerId,
                'borrower_id' => $borrowerId,
                'invoice_id' => $invoice->id,
                'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id,
                'transaction_id' => $gatewayResult['transaction_id'] ?? ('TX_' . uniqid()),
                'amount' => $invoice->payment_amount,
                'currency' => strtoupper($invoice->currency ?? 'USD'),
                'payment_from' => $providerName ?? 'Default',
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => $gatewayResult['raw'] ?? [],
            ]);

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'payment_date' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => $gatewayResult,
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
