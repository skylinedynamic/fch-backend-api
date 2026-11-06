<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Gives the admin-panel live E2E one manual Cash payment awaiting approval
 * (Processing) on a host client, so the approve / reject panel can be checked
 * (Fch-admin-frontend-react: e2e/sidebar/payments/payment-approval-environment.live.spec.ts).
 *
 * Local/testing only. Idempotent: the same E2E payment is reused while it is
 * still Processing. E2E_PAYMENT_ENVIRONMENT (sandbox | production, default
 * sandbox) sets the environment it is recorded in on every run. Prints the
 * payment id as "E2E_PAYMENT_ID=<id>".
 *
 *   php artisan db:seed --class=E2EProcessingManualPaymentSeeder
 *   E2E_PAYMENT_ENVIRONMENT=production php artisan db:seed --class=E2EProcessingManualPaymentSeeder
 */
class E2EProcessingManualPaymentSeeder extends Seeder
{
    private const TRANSACTION_PREFIX = 'E2E-ENV-';
    private const METHOD = 'Cash Payment';

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EProcessingManualPaymentSeeder only runs in local/testing.');
        }

        $mode = env('E2E_PAYMENT_ENVIRONMENT', PaymentProvider::MODE_SANDBOX);
        if (!in_array($mode, PaymentProvider::MODES, true)) {
            throw new RuntimeException('E2E_PAYMENT_ENVIRONMENT must be one of: ' . implode(', ', PaymentProvider::MODES));
        }
        $isProduction = (bool) Payment::environmentFromMode($mode);

        $payment = Payment::where('transaction_id', 'like', self::TRANSACTION_PREFIX . '%')
            ->where('status', Payment::STATUS_PROCESSING)
            ->first();

        if (!$payment) {
            $host = Partner::host();
            $borrower = Borrower::where('partner_id', $host->id)->orderBy('id')->firstOrFail();

            $invoice = Invoice::create([
                'invoice_id' => 'INV-E2E' . strtoupper(uniqid()),
                'partner_id' => $host->id,
                'borrower_id' => $borrower->id,
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
                'customer_name' => trim($borrower->first_name . ' ' . $borrower->last_name),
                'email' => $borrower->email,
                'payment_amount' => 1.00,
                'month_count' => 1,
                'status' => Invoice::STATUS_PROCESSING,
                'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
                'note' => 'E2E test invoice - a manual payment awaiting approval.',
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1,
                'is_platform_fee' => 0,
                'is_partner_paying' => 0,
            ]);

            $payment = Payment::create([
                'partner_id' => $host->id,
                'borrower_id' => $borrower->id,
                'invoice_id' => $invoice->id,
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
                'transaction_id' => self::TRANSACTION_PREFIX . strtoupper(uniqid()),
                'payment_from' => self::METHOD,
                'payment_type' => self::METHOD,
                'amount' => 1.00,
                'details' => ['mode' => 'manual', 'provider' => self::METHOD],
                'status' => Payment::STATUS_PROCESSING,
                'is_environment' => $isProduction,
                'transaction_date' => now(),
                'is_partner_paying' => false,
                'is_manual' => true,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1,
            ]);
        }

        $payment->update(['is_environment' => $isProduction]);

        $this->command?->info('E2E_PAYMENT_ID=' . $payment->id);
    }
}
