<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Gives the admin-panel live E2E one borrower fee a partner still owes the host:
 * an E2E client of the first Active non-host partner, with an Unpaid $800
 * borrower fee on the client's subscription, as EnrollmentService raises it
 * (Fch-admin-frontend-react: e2e/sidebar/upcoming-billings/borrower-fee.live.spec.ts).
 *
 * Local/testing only. Idempotent: the same client, subscription and fee are
 * reused; the fee is set back to Unpaid on each run. Prints
 * "E2E_FEE_INVOICE_ID=<id>" and "E2E_FEE_PARTNER=<name>".
 *
 *   php artisan db:seed --class=E2EUnpaidBorrowerFeeSeeder
 */
class E2EUnpaidBorrowerFeeSeeder extends Seeder
{
    private const CLIENT_EMAIL = 'e2e.borrower-fee@example.test';

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EUnpaidBorrowerFeeSeeder only runs in local/testing.');
        }

        $partner = Partner::where('is_host', false)->where('status', Partner::STATUS_ACTIVE)->orderBy('id')->firstOrFail();

        $client = Borrower::firstOrCreate(['email' => self::CLIENT_EMAIL], [
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'E2E',
            'last_name' => 'Fee Client',
        ]);

        $subscription = Subscription::firstOrCreate(
            ['entity_type' => Borrower::class, 'entity_id' => $client->id],
            [
                'installment_count' => 1,
                'installments_paid' => 0,
                'payable_amount' => 1,
                'total_subscription_amount' => 1,
                'current_phase' => Subscription::PHASE_ENROLLMENT,
                'status' => Subscription::STATUS_PENDING,
            ]
        );

        $fee = Invoice::firstOrCreate(
            ['subscription_id' => $subscription->id, 'type' => Invoice::INVOICE_TYPE_BORROWER_FEE],
            [
                'invoice_id' => 'INV-E2E' . strtoupper(uniqid()),
                'partner_id' => $partner->id,
                'borrower_id' => $client->id,
                'entity_type' => Partner::class,
                'entity_id' => $partner->id,
                'customer_name' => $partner->full_name,
                'email' => $partner->email,
                'payment_amount' => 800,
                'status' => Invoice::STATUS_UNPAID,
                'is_partner_paying' => true,
                'is_platform_fee' => false,
                'currency' => 'USD',
                'currency_symbol' => '$',
                'conversion_rate' => 1,
                'note' => 'E2E borrower fee - safe to leave unpaid.',
            ]
        );
        $fee->update(['status' => Invoice::STATUS_UNPAID]);

        $this->command?->info('E2E_FEE_INVOICE_ID=' . $fee->id);
        $this->command?->info('E2E_FEE_PARTNER=' . $partner->full_name);
    }
}
