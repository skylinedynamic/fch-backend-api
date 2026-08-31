<?php

namespace Tests\Unit\Services\GlobalPayment\Stripe;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Services\GlobalPayment\Stripe\StripeWebhookService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for StripeWebhookService.
 *
 * NOTE: This service is confirmed dead code from an HTTP standpoint — no
 * controller/route in this codebase wires a request to
 * StripeWebhookService::handleWebhook(). These tests invoke it directly,
 * constructing the payload array by hand, the way it would be called if a
 * webhook route/controller existed.
 */
class StripeWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StripeWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new StripeWebhookService;
    }

    public function test_payment_intent_succeeded_marks_invoice_paid_and_payment_succeeded(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();
        $payment = Payment::factory()->forBorrower($borrower)->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => 'pi_test_123',
            'status' => Payment::STATUS_PENDING,
        ]);

        $result = $this->service->handleWebhook([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_123',
                    'customer' => 'cus_abc',
                    'amount_received' => 10000,
                ],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
    }

    public function test_payment_intent_succeeded_is_idempotent_when_invoice_already_paid(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();
        $paidAt = $invoice->payment_date;

        $payment = Payment::factory()->forBorrower($borrower)->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => 'pi_test_already_paid',
            'status' => Payment::STATUS_SUCCEEDED,
        ]);

        $result = $this->service->handleWebhook([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_already_paid',
                    'amount_received' => 10000,
                ],
            ],
        ]);

        $this->assertTrue($result);
        // No double-processing: invoice stays Paid, payment_date does not change.
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals($paidAt, $invoice->fresh()->payment_date);
    }

    public function test_payment_intent_succeeded_no_ops_when_no_local_payment_record_found(): void
    {
        // No Payment row with this transaction_id exists locally.
        $result = $this->service->handleWebhook([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_unknown',
                    'amount_received' => 5000,
                ],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_payment_intent_failed_marks_invoice_failed_and_payment_failed(): void
    {
        $borrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();
        $payment = Payment::factory()->forBorrower($borrower)->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => 'pi_failed_123',
            'status' => Payment::STATUS_PENDING,
        ]);

        $result = $this->service->handleWebhook([
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_failed_123',
                    'last_payment_error' => ['message' => 'Card declined'],
                ],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(Invoice::STATUS_FAILED, $invoice->fresh()->status);
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_payment_intent_failed_marks_related_subscription_past_due(): void
    {
        $borrower = Borrower::factory()->create();
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);
        Payment::factory()->forBorrower($borrower)->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => 'pi_failed_sub',
        ]);

        $this->service->handleWebhook([
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => ['id' => 'pi_failed_sub'],
            ],
        ]);

        $this->assertSame('Past Due', $subscription->fresh()->status);
    }

    public function test_unrecognized_event_type_is_ignored_without_error(): void
    {
        $result = $this->service->handleWebhook([
            'type' => 'customer.subscription.created',
            'data' => ['object' => []],
        ]);

        $this->assertTrue($result);
    }
}
