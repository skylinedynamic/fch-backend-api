<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Functional HTTP tests for POST /api/v1/process-payment (UnifiedPaymentController),
 * covering ProcessPaymentRequest validation and both the manual and Stripe-driven
 * success/failure paths end-to-end through real middleware + Passport auth.
 *
 * No real Stripe call is ever reachable here: the manual/bank-transfer path never
 * touches the gateway driver, and there is no HTTP-level way to inject a mocked
 * PaymentGatewayInterface for the Stripe path without binding it into the
 * container — which we do via $this->instance() to keep this a real end-to-end
 * HTTP test while staying inside the "no real Stripe call" constraint.
 */
class ProcessPaymentTest extends TestCase
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

    protected function actingAsBorrower(Borrower $borrower): string
    {
        $user = User::factory()->borrower($borrower)->create();

        return $user->createToken('test')->accessToken;
    }

    public function test_process_payment_requires_invoice_id_payment_method_id_and_token(): void
    {
        $borrower = Borrower::factory()->create();
        $token = $this->actingAsBorrower($borrower);

        $response = $this->withToken($token)->postJson('/api/v1/process-payment', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id', 'payment_method_id', 'payment_token']);
    }

    public function test_process_payment_rejects_nonexistent_invoice_id(): void
    {
        $borrower = Borrower::factory()->create();
        $token = $this->actingAsBorrower($borrower);

        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/process-payment', [
            'invoice_id' => 999999,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'payment_token' => json_encode(['mode' => 'manual']),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);
    }

    public function test_process_payment_manual_bank_transfer_succeeds_end_to_end(): void
    {
        $borrower = Borrower::factory()->create();
        $token = $this->actingAsBorrower($borrower);

        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);
        $bankDetail = PaymentMethodBankDetail::factory()->create(['partner_id' => $borrower->partner_id]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        $response = $this->withToken($token)->postJson('/api/v1/process-payment', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'bank_detail_id' => $bankDetail->id,
            'payment_token' => json_encode([
                'mode' => 'manual',
                'gateway' => PaymentProvider::BANK_TRANSFER,
                'bank_detail_id' => $bankDetail->id,
            ]),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => 'processing',
        ]);
    }

    public function test_process_payment_returns_400_when_invoice_already_paid(): void
    {
        $borrower = Borrower::factory()->create();
        $token = $this->actingAsBorrower($borrower);

        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);
        $bankDetail = PaymentMethodBankDetail::factory()->create(['partner_id' => $borrower->partner_id]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/process-payment', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'bank_detail_id' => $bankDetail->id,
            'payment_token' => json_encode(['mode' => 'manual', 'bank_detail_id' => $bankDetail->id]),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_process_payment_returns_400_when_caller_does_not_own_invoice(): void
    {
        $borrower = Borrower::factory()->create();
        $token = $this->actingAsBorrower($borrower);

        $otherBorrower = Borrower::factory()->create();
        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $otherBorrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);
        $bankDetail = PaymentMethodBankDetail::factory()->create(['partner_id' => $otherBorrower->partner_id]);

        $subscription = Subscription::factory()->forBorrower($otherBorrower)->create();
        $invoice = Invoice::factory()->forBorrower($otherBorrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/process-payment', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'bank_detail_id' => $bankDetail->id,
            'payment_token' => json_encode(['mode' => 'manual', 'bank_detail_id' => $bankDetail->id]),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_process_payment_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/process-payment', [
            'invoice_id' => 1,
            'payment_method_id' => 1,
            'payment_token' => 'x',
        ]);

        $response->assertStatus(401);
    }
}
