<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Gives the borrower-portal E2E account one small unpaid invoice to pay with a
 * Stripe test card (fch-borrower-react: e2e/sidebar/invoices/invoice-payment.spec.ts).
 *
 * Local/testing only. Idempotent: if the borrower already has an unpaid
 * borrower_fee invoice, nothing is created. A `borrower_fee` on the Borrower
 * entity with no subscription is used so paying it has no subscription or
 * partner auto-charge side effects.
 *
 *   php artisan db:seed --class=E2EUnpaidInvoiceSeeder
 *   E2E_BORROWER_EMAIL=someone@example.com php artisan db:seed --class=E2EUnpaidInvoiceSeeder
 */
class E2EUnpaidInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EUnpaidInvoiceSeeder only runs in local/testing.');
        }

        $email = env('E2E_BORROWER_EMAIL', 'craig-borrower@fourcornerholdings.com');
        $user = User::where('email', $email)->firstOrFail();
        $borrower = Borrower::findOrFail($user->entity_id);

        $existing = Invoice::where('entity_type', Borrower::class)
            ->where('entity_id', $borrower->id)
            ->where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
            ->where('status', Invoice::STATUS_UNPAID)
            ->first();

        if ($existing) {
            $this->command?->info("Unpaid E2E invoice already exists: {$existing->invoice_id} (id {$existing->id})");
            return;
        }

        $invoice = Invoice::create([
            'invoice_id' => 'INV-E2E' . strtoupper(uniqid()),
            'partner_id' => $borrower->partner_id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'customer_name' => $user->name,
            'email' => $user->email,
            'payment_amount' => 1.00,
            'month_count' => 1,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'note' => 'E2E test invoice - safe to pay with a Stripe test card.',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'is_platform_fee' => 0,
            'is_partner_paying' => 0,
        ]);

        $this->command?->info("Created unpaid E2E invoice {$invoice->invoice_id} (id {$invoice->id})");
    }
}
