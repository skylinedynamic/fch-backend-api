<?php

namespace Tests\Feature\Admin\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Functional HTTP tests for the host-admin payment approval workflow:
 *  POST /api/v1/common/payments/{id}/approve
 *  POST /api/v1/common/payments/{id}/reject
 */
class PaymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Mail::fake();
    }

    protected function actingAsHostAdmin(): array
    {
        $host = Partner::factory()->host()->create();
        $user = User::factory()->partner($host)->create();
        $token = $user->createToken('test')->accessToken;

        return [$host, $token];
    }

    public function test_approve_transitions_processing_payment_to_succeeded(): void
    {
        [$host, $token] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/common/payments/{$payment->id}/approve", [
            'note' => 'Confirmed via bank statement',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_approve_returns_422_when_payment_is_not_processing(): void
    {
        [$host, $token] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();
        $payment = Payment::factory()->forBorrower($borrower)->succeeded()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/common/payments/{$payment->id}/approve", []);

        $response->assertStatus(422);
        $response->assertJson(['status' => false]);
    }

    public function test_approve_requires_authentication(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->postJson("/api/v1/common/payments/{$payment->id}/approve", []);

        $response->assertStatus(401);
    }

    public function test_reject_requires_a_reason(): void
    {
        [$host, $token] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/common/payments/{$payment->id}/reject", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_reject_with_reason_transitions_payment_to_rejected_and_invoice_to_unpaid(): void
    {
        [$host, $token] = $this->actingAsHostAdmin();

        $borrower = Borrower::factory()->create(['partner_id' => $host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $host->id,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/common/payments/{$payment->id}/reject", [
            'reason' => 'Transaction reference not found in bank statement',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame(Payment::STATUS_REJECTED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_non_host_partner_cannot_approve_another_partners_payment(): void
    {
        $otherPartner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->processing()->create();
        $payment = Payment::factory()->forBorrower($borrower)->processing()->create([
            'invoice_id' => $invoice->id,
            'partner_id' => $otherPartner->id,
        ]);

        $myPartner = Partner::factory()->create();
        $user = User::factory()->partner($myPartner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson("/api/v1/common/payments/{$payment->id}/approve", []);

        $response->assertStatus(422);
        $response->assertJson(['status' => false]);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }
}
