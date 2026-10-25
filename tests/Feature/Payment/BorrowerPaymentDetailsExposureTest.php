<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\Borrower\Payment\PaymentController;
use App\Http\Resources\Borrower\Payment\PaymentResource;
use App\Models\Borrower\Borrower;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-062: the borrower's payment detail sent the whole `details` column as
 * raw_details - for a card payment, the gateway's PaymentIntent with its
 * client_secret, customer and charge ids - and the portal printed every key.
 * A borrower now gets only the readable verification fields.
 */
#[CoversClass(PaymentController::class)]
#[CoversClass(PaymentResource::class)]
class BorrowerPaymentDetailsExposureTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->me);
    }

    private function payment(array $details, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'partner_id' => $this->me->partner_id,
            'borrower_id' => $this->me->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->me->entity_id,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => 2497.00,
            'currency' => 'USD',
            'payment_type' => 'Card',
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
            'details' => $details,
        ], $attributes));
    }

    private const PAYMENT_INTENT = [
        'id' => 'pi_3Abc',
        'object' => 'payment_intent',
        'amount' => 249700,
        'client_secret' => 'pi_3Abc_secret_XyZ',
        'customer' => 'cus_Q1',
        'latest_charge' => 'ch_9',
        'created' => 1760500000,
        'metadata' => ['invoice_id' => '12'],
        'status' => 'succeeded',
    ];

    #[Test]
    public function a_card_payment_never_sends_the_gateways_payment_intent(): void
    {
        $payment = $this->payment(self::PAYMENT_INTENT);

        $response = $this->getJson("/api/v1/borrower/payments/{$payment->id}")->assertOk();

        $this->assertSame([], $response->json('data.raw_details'));
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('cus_Q1', $response->getContent());
        $this->assertStringNotContainsString('ch_9', $response->getContent());
    }

    #[Test]
    public function the_payment_list_does_not_send_it_either(): void
    {
        $this->payment(self::PAYMENT_INTENT);

        $response = $this->postJson('/api/v1/borrower/payments/fetch-payments')->assertOk();

        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('cus_Q1', $response->getContent());
    }

    #[Test]
    public function an_approved_cash_payment_keeps_its_readable_verification_fields(): void
    {
        $payment = $this->payment([
            'mode' => 'manual',
            'provider' => 'Cash Payment',
            'bank_detail_id' => 4,
            'bank_name' => 'First Bank',
            'account_name' => 'Acme Funding',
            'account_number' => '000123456789',
            'routing_number' => '021000021',
            'instructions' => 'Use your invoice number as the reference.',
            'transaction_number' => 'DEP-5521',
            'decision' => 'approved',
            'approval_note' => 'Deposit seen',
            'approved_at' => '2026-10-15 14:00:00',
            'approved_by' => 'Olive Owner',
            'approved_by_id' => 3,
        ], ['payment_type' => 'Cash Payment', 'is_manual' => true]);

        $details = $this->getJson("/api/v1/borrower/payments/{$payment->id}")->assertOk()->json('data.raw_details');

        $this->assertSame('First Bank', $details['bank_name']);
        $this->assertSame('Acme Funding', $details['account_name']);
        $this->assertSame('DEP-5521', $details['transaction_number']);
        $this->assertSame('Deposit seen', $details['approval_note']);
        $this->assertSame('Olive Owner', $details['approved_by']);
        $this->assertArrayHasKey('approved_at', $details);
        // The account it was paid into, by its last four only.
        $this->assertSame('****6789', $details['account_number']);
        foreach (['routing_number', 'bank_detail_id', 'approved_by_id', 'mode'] as $internal) {
            $this->assertArrayNotHasKey($internal, $details);
        }
    }

    #[Test]
    public function a_rejected_payment_keeps_who_rejected_it_and_why(): void
    {
        $payment = $this->payment([
            'decision' => 'rejected',
            'rejection_reason' => 'No deposit found',
            'rejected_at' => '2026-10-15 14:00:00',
            'rejected_by' => 'Olive Owner',
            'rejected_by_id' => 3,
        ], ['payment_type' => 'Cash Payment', 'is_manual' => true, 'status' => Payment::STATUS_REJECTED]);

        $details = $this->getJson("/api/v1/borrower/payments/{$payment->id}")->assertOk()->json('data.raw_details');

        $this->assertSame('No deposit found', $details['rejection_reason']);
        $this->assertSame('Olive Owner', $details['rejected_by']);
        $this->assertArrayNotHasKey('rejected_by_id', $details);
    }

    #[Test]
    public function a_failed_payment_keeps_its_error_but_not_what_was_sent_to_the_gateway(): void
    {
        $payment = $this->payment([
            'error' => 'Your card was declined.',
            'type' => 'card_error',
            'input_data' => ['amount' => 249700, 'payment_method' => 'pm_123'],
        ], ['status' => Payment::STATUS_FAILED]);

        $details = $this->getJson("/api/v1/borrower/payments/{$payment->id}")->assertOk()->json('data.raw_details');

        $this->assertSame(['error' => 'Your card was declined.'], $details);
    }
}
