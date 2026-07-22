<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class UnifiedPaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected Payment $paymentRecord
    ) {}

    /**
     * Process multi-gateway payment (one-time or recurring).
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function processPayment(array $data): array
    {
        $partnerId = $this->resolvePartnerId($data);
        $providerName = $data['provider'] ?? null;

        // Resolve driver from PaymentGatewayManager
        $driver = $this->gatewayManager->resolveForPartner($partnerId, $providerName);

        $invoice = !empty($data['invoice_id']) ? Invoice::where('id', $data['invoice_id'])->first() : null;
        $paymentType = $data['payment_type'] ?? 'one_time';

        DB::beginTransaction();
        try {
            if ($paymentType === 'recurring') {
                $gatewayResult = $driver->createSubscription([
                    'customer_id' => $data['customer_id'] ?? null,
                    'plan_id' => $data['plan_id'] ?? null,
                    'email' => $data['email'] ?? null,
                    'name' => $data['name'] ?? null,
                ]);
            } else {
                $gatewayResult = $driver->charge([
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'USD',
                    'payment_method_id' => $data['payment_method_id'],
                    'description' => $data['description'] ?? 'Payment',
                ]);
            }

            if (empty($gatewayResult['success'])) {
                throw new Exception("Payment charge failed via " . ($providerName ?? 'gateway'));
            }

            // Create local payment ledger entry
            $borrowerId = null;
            if ($invoice && $invoice->entity_type === Borrower::class) {
                $borrowerId = $invoice->entity_id;
            } elseif (!empty($data['borrower_id'])) {
                $borrowerId = $data['borrower_id'];
            }

            $payment = $this->paymentRecord->createRecord([
                'partner_id' => $partnerId,
                'borrower_id' => $borrowerId,
                'invoice_id' => $invoice?->id,
                'entity_type' => $invoice?->entity_type ?? ($data['entity_type'] ?? null),
                'entity_id' => $invoice?->entity_id ?? ($data['entity_id'] ?? null),
                'transaction_id' => $gatewayResult['transaction_id'] ?? ($gatewayResult['subscription_id'] ?? ('TX_' . uniqid())),
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'payment_from' => $providerName ?? 'Default',
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => $gatewayResult['raw'] ?? [],
            ]);

            // Update invoice status if applicable
            if ($invoice) {
                $invoice->update([
                    'status' => Invoice::STATUS_PAID,
                    'payment_date' => now(),
                ]);
            }

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
     * Resolve target partner ID from input data or authentication context.
     */
    protected function resolvePartnerId(array $data): ?int
    {
        if (!empty($data['partner_id'])) {
            return (int) $data['partner_id'];
        }

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::find($data['invoice_id']);
            if ($invoice) {
                if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
                    return Partner::where('is_host', true)->first()?->id;
                }
                return $invoice->partner_id;
            }
        }

        if (Auth::check()) {
            if (in_array(Auth::user()->user_type, [User::PARTNER, User::ADMIN], true)) {
                return Partner::where('is_host', true)->first()?->id;
            }
            return Auth::user()->partner_id;
        }

        return Partner::where('is_host', true)->first()?->id;
    }
}
