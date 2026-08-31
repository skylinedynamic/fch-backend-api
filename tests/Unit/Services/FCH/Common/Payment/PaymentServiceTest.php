<?php

namespace Tests\Unit\Services\FCH\Common\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Services\FCH\Common\Payment\PaymentService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Mail::fake();

        $this->service = new PaymentService;
    }

    protected function actingAsHostAdmin(): array
    {
        $host = Partner::factory()->host()->create();
        $user = User::factory()->partner($host)->create();
        $this->actingAs($user);

        return [$host, $user];
    }

    // ─────────────────────────────────────────────────────────────
    // approvePayment
    // ─────────────────────────────────────────────────────────────

    public function test_approve_payment_transitions_processing_payment_and_invoice_to_succeeded_paid(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->approvePayment($payment->id, ['note' => 'Looks good']);

        $this->assertTrue($result['status']);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('approved', $payment->fresh()->details['decision']);
        $this->assertSame('Looks good', $payment->fresh()->details['approval_note']);
    }

    public function test_approve_payment_fails_when_payment_is_not_processing(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();
        $payment = Payment::factory()->forBorrower($borrower)->succeeded()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->approvePayment($payment->id, []);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
    }

    public function test_approve_payment_fails_when_payment_not_found(): void
    {
        $this->actingAsHostAdmin();

        $result = $this->service->approvePayment(999999, []);

        $this->assertFalse($result['status']);
    }

    public function test_approve_payment_fails_when_unauthenticated(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
        ]);

        $result = $this->service->approvePayment($payment->id, []);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_approve_payment_fails_when_borrower_user_attempts_to_approve(): void
    {
        $borrower = Borrower::factory()->create();
        $borrowerUser = User::factory()->borrower($borrower)->create();
        $this->actingAs($borrowerUser);

        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
        ]);

        $result = $this->service->approvePayment($payment->id, []);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_approve_payment_fails_when_non_host_partner_approves_other_partners_payment(): void
    {
        $otherPartner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $otherPartner->id,
        ]);

        // A different non-host partner tries to approve a payment they don't own.
        $myPartner = Partner::factory()->create();
        $myUser = User::factory()->partner($myPartner)->create();
        $this->actingAs($myUser);

        $result = $this->service->approvePayment($payment->id, []);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_approve_payment_uses_row_locking_transaction(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->approvePayment($payment->id, []);

        // approved_by_id captured from the row-locked, freshly authorized payment.
        $this->assertTrue($result['status']);
        $this->assertEquals(auth()->id(), $payment->fresh()->details['approved_by_id']);
    }

    // ─────────────────────────────────────────────────────────────
    // rejectPayment
    // ─────────────────────────────────────────────────────────────

    public function test_reject_payment_requires_a_reason(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->rejectPayment($payment->id, ['reason' => '']);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_reject_payment_transitions_payment_to_rejected_and_invoice_back_to_unpaid(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->rejectPayment($payment->id, ['reason' => 'Bank reference did not match']);

        $this->assertTrue($result['status']);
        $this->assertSame(Payment::STATUS_REJECTED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('rejected', $payment->fresh()->details['decision']);
        $this->assertSame('Bank reference did not match', $payment->fresh()->details['rejection_reason']);
    }

    public function test_reject_payment_fails_when_payment_is_not_processing(): void
    {
        [$host] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();
        $payment = Payment::factory()->forBorrower($borrower)->succeeded()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $result = $this->service->rejectPayment($payment->id, ['reason' => 'Too late']);

        $this->assertFalse($result['status']);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
    }

    public function test_reject_payment_fails_when_unauthenticated(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
        ]);

        $result = $this->service->rejectPayment($payment->id, ['reason' => 'x']);

        $this->assertFalse($result['status']);
    }
}
