<?php

namespace Tests\Feature\Invoice;

use App\Console\Commands\ReconcilePaidInvoices;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-005: INV-20260719001 was paid by Stripe on 19 Jul (older code) but stayed
 * Unpaid. `php artisan invoices:reconcile-paid` finds every Unpaid invoice its
 * succeeded payments already cover and settles it the way a successful payment
 * does; --dry-run only lists them.
 */
#[CoversClass(ReconcilePaidInvoices::class)]
class ReconcilePaidInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Borrower $client;
    private Subscription $subscription;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $this->client = $this->makeBorrower($partner, ['first_name' => 'Kirsten', 'last_name' => 'Carter']);

        $this->subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $this->client->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 2497.00,
            'total_subscription_amount' => 2497.00,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->invoice = $this->invoiceOf(2497.00, 'INV-20260719001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoiceOf(float $amount, string $number): Invoice
    {
        return Invoice::create([
            'invoice_id' => $number,
            'subscription_id' => $this->subscription->id,
            'partner_id' => $this->client->partner_id,
            'borrower_id' => $this->client->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->client->id,
            'payment_amount' => $amount,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function paid(Invoice $invoice, float $amount, string $status = Payment::STATUS_SUCCEEDED, string $on = '2026-07-19 15:30:00'): void
    {
        $payment = (new Payment())->createRecord(['is_environment' => PaymentProvider::ENVIRONMENT_SANDBOX, 
            'invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'pi_' . uniqid(),
            'amount' => $amount,
            'currency' => 'USD',
            'payment_from' => PaymentProvider::STRIPE,
            'status' => $status,
        ]);
        Payment::whereKey($payment->id)->update(['created_at' => $on]);
    }

    #[Test]
    public function a_dry_run_lists_the_invoice_and_changes_nothing(): void
    {
        $this->paid($this->invoice, 2497.00);

        $this->artisan('invoices:reconcile-paid', ['--dry-run' => true])
            ->expectsOutputToContain('INV-20260719001')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame(Subscription::PHASE_ENROLLMENT, $this->subscription->fresh()->current_phase);
    }

    #[Test]
    public function it_settles_the_invoice_on_the_day_it_was_paid_and_moves_the_subscription_on(): void
    {
        $this->paid($this->invoice, 2497.00);

        $this->artisan('invoices:reconcile-paid')->assertSuccessful();

        $invoice = $this->invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('2026-07-19', $invoice->payment_date->toDateString());

        // A client's one time enrollment, paid in full: finished.
        $subscription = $this->subscription->fresh();
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
        $this->assertNotNull($subscription->enrollment_completed_at);
    }

    #[Test]
    public function it_leaves_alone_what_its_payments_do_not_cover(): void
    {
        $this->paid($this->invoice, 1000.00);
        $failedOnly = $this->invoiceOf(500.00, 'INV-FAILED-ONLY');
        $this->paid($failedOnly, 500.00, Payment::STATUS_FAILED);

        $this->artisan('invoices:reconcile-paid')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $failedOnly->fresh()->status);
    }

    #[Test]
    public function running_it_again_finds_nothing_left_to_do(): void
    {
        $this->paid($this->invoice, 2497.00);
        $this->artisan('invoices:reconcile-paid')->assertSuccessful();
        $settledOn = $this->subscription->fresh()->enrollment_completed_at;

        $this->artisan('invoices:reconcile-paid')
            ->expectsOutputToContain('No unpaid invoice')
            ->assertSuccessful();

        $this->assertEquals($settledOn, $this->subscription->fresh()->enrollment_completed_at);
    }
}
