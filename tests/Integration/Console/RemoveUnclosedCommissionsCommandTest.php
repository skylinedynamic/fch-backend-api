<?php

namespace Tests\Integration\Console;

use App\Console\Commands\RemoveUnclosedCommissions;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Decision 2026-11-03 (F177): commissions exist only for Closed (Completed)
 * applications - they are set while closing. This one-off removes the others
 * (from the old Commission Details card) with their payout, the payout's
 * commission-payout invoice and its payment; nothing of a Closed (Completed)
 * application, and no other invoice or payment, is touched.
 */
#[CoversClass(RemoveUnclosedCommissions::class)]
class RemoveUnclosedCommissionsCommandTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makeHostPartner();
    }

    #[Test]
    public function a_dry_run_reports_and_changes_nothing(): void
    {
        $open = $this->paidCommissionFor(FundingApplication::APPROVED_FINAL);

        $this->artisan('commissions:remove-unclosed', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing will be changed.')
            ->expectsOutputToContain('Commissions to remove: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('commissions', ['id' => $open['commission'], 'deleted_at' => null]);
        $this->assertDatabaseHas('commission_payouts', ['id' => $open['payout']]);
        $this->assertNotSoftDeleted('invoices', ['id' => $open['invoice']]);
        $this->assertNotSoftDeleted('payments', ['id' => $open['payment']]);
    }

    #[Test]
    public function it_removes_the_commission_payout_invoice_and_payment_of_an_application_that_is_not_closed(): void
    {
        $open = $this->paidCommissionFor(FundingApplication::OFFER_ISSUED);
        $unpaid = $this->commissionFor(FundingApplication::DRAFT);

        $this->artisan('commissions:remove-unclosed', ['--force' => true])
            ->expectsOutputToContain('Removed 2 commission(s), 1 payout(s), 1 invoice(s), 1 payment(s).')
            ->assertSuccessful();

        $this->assertSoftDeleted('commissions', ['id' => $open['commission']]);
        $this->assertSoftDeleted('commissions', ['id' => $unpaid]);
        $this->assertDatabaseMissing('commission_payouts', ['id' => $open['payout']]);
        $this->assertSoftDeleted('invoices', ['id' => $open['invoice']]);
        $this->assertSoftDeleted('payments', ['id' => $open['payment']]);
    }

    #[Test]
    public function a_closed_completed_application_keeps_its_commission_invoice_and_payment(): void
    {
        $closed = $this->paidCommissionFor(FundingApplication::CLOSED_COMPLETED);
        $this->paidCommissionFor(FundingApplication::DENIED);

        $this->artisan('commissions:remove-unclosed', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('commissions', ['id' => $closed['commission'], 'deleted_at' => null]);
        $this->assertDatabaseHas('commission_payouts', ['id' => $closed['payout']]);
        $this->assertNotSoftDeleted('invoices', ['id' => $closed['invoice']]);
        $this->assertNotSoftDeleted('payments', ['id' => $closed['payment']]);
    }

    #[Test]
    public function a_commission_whose_application_is_gone_is_removed(): void
    {
        $orphan = $this->commissionFor(FundingApplication::CLOSED_COMPLETED);
        DB::table('funding_applications')->where('id', Commission::findOrFail($orphan)->funding_application_id)->delete();

        $this->artisan('commissions:remove-unclosed', ['--force' => true])->assertSuccessful();

        $this->assertSoftDeleted('commissions', ['id' => $orphan]);
    }

    #[Test]
    public function other_invoices_and_payments_are_never_touched(): void
    {
        $open = $this->paidCommissionFor(FundingApplication::SUBMITTED);
        // A subscription invoice that the payout row somehow points at - never a commission payout's.
        $subscriptionInvoice = $this->invoice(Invoice::INVOICE_TYPE_ENROLLMENT);
        DB::table('commission_payouts')->where('id', $open['payout'])->update(['invoice_id' => $subscriptionInvoice]);
        $subscriptionPayment = $this->payment($subscriptionInvoice);
        $unrelatedInvoice = $this->invoice(Invoice::INVOICE_TYPE_PLATFORM_FEE);
        $unrelatedPayment = $this->payment($unrelatedInvoice);

        $this->artisan('commissions:remove-unclosed', ['--force' => true])->assertSuccessful();

        $this->assertNotSoftDeleted('invoices', ['id' => $subscriptionInvoice]);
        $this->assertNotSoftDeleted('payments', ['id' => $subscriptionPayment]);
        $this->assertNotSoftDeleted('invoices', ['id' => $unrelatedInvoice]);
        $this->assertNotSoftDeleted('payments', ['id' => $unrelatedPayment]);
        // The payout's own payment (linked by payment_id) still goes with it.
        $this->assertSoftDeleted('payments', ['id' => $open['payment']]);
    }

    #[Test]
    public function without_force_it_asks_first_and_stops_on_no(): void
    {
        $open = $this->commissionFor(FundingApplication::DRAFT);

        $this->artisan('commissions:remove-unclosed')
            ->expectsConfirmation('Remove 1 commission(s) and their payouts, invoices and payments? Payouts are deleted; commissions, invoices and payments are soft-deleted.', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('commissions', ['id' => $open, 'deleted_at' => null]);
    }

    #[Test]
    public function with_nothing_to_remove_it_says_so_and_a_rerun_is_a_no_op(): void
    {
        $this->paidCommissionFor(FundingApplication::CLOSED_COMPLETED);

        $this->artisan('commissions:remove-unclosed', ['--force' => true])
            ->expectsOutputToContain('Every commission belongs to a Closed (Completed) application. Nothing to remove.')
            ->assertSuccessful();
    }

    #[Test]
    public function a_failure_leaves_that_commission_as_it_was(): void
    {
        $open = $this->paidCommissionFor(FundingApplication::DRAFT);
        // Make the payout delete fail part-way, after the payment went.
        DB::statement('CREATE TRIGGER block_payout_delete BEFORE DELETE ON commission_payouts BEGIN SELECT RAISE(ABORT, \'blocked\'); END;');

        $this->artisan('commissions:remove-unclosed', ['--force' => true])
            ->expectsOutputToContain("Commission #{$open['commission']} was not removed")
            ->assertFailed();

        $this->assertDatabaseHas('commissions', ['id' => $open['commission'], 'deleted_at' => null]);
        $this->assertNotSoftDeleted('payments', ['id' => $open['payment']]);
        $this->assertNotSoftDeleted('invoices', ['id' => $open['invoice']]);
    }

    // -- Fixtures ---------------------------------------------------------------

    private function application(string $status): int
    {
        $borrower = $this->makeBorrower($this->partner);

        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function commissionFor(string $status): int
    {
        $applicationId = $this->application($status);

        return DB::table('commissions')->insertGetId([
            'funding_application_id' => $applicationId,
            'borrower_id' => DB::table('funding_applications')->where('id', $applicationId)->value('borrower_id'),
            'partner_id' => $this->partner->id,
            'total_commission' => 10,
            'admin_commission' => 100,
            'partner_commission' => 0,
            'total_commission_amount' => 1000,
            'admin_commission_amount' => 1000,
            'partner_commission_amount' => 0,
            'commission_status' => Commission::STATUS_PROCESSING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{commission: int, payout: int, invoice: int, payment: int} */
    private function paidCommissionFor(string $status): array
    {
        $commission = $this->commissionFor($status);
        $invoice = $this->invoice(Invoice::INVOICE_TYPE_COMMISSION_PAYOUT);
        $payment = $this->payment($invoice);

        $payout = DB::table('commission_payouts')->insertGetId([
            'commission_id' => $commission,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'payout_type' => 'partner',
            'invoice_id' => $invoice,
            'payment_id' => $payment,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('commission', 'payout', 'invoice', 'payment');
    }

    private function invoice(string $type): int
    {
        return DB::table('invoices')->insertGetId([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'payment_amount' => 1000,
            'status' => Invoice::STATUS_PAID,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(int $invoiceId): int
    {
        return DB::table('payments')->insertGetId([
            'partner_id' => $this->partner->id,
            'invoice_id' => $invoiceId,
            'amount' => 1000,
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
