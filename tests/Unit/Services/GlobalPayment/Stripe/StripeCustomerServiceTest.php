<?php

namespace Tests\Unit\Services\GlobalPayment\Stripe;

use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for StripeCustomerService.
 *
 * fetchCardDetails() is a compliance-critical method: it must NEVER leak a full
 * card PAN, only a masked "**** **** **** 1234" style string plus the cardholder
 * name. These tests assert that invariant explicitly.
 *
 * TESTABILITY NOTE: Stripe\PaymentMethod::retrieve() and Stripe\Customer::* are
 * called as direct static methods on the Stripe SDK (no wrapper/interface seam),
 * so they cannot be mocked via constructor injection. We use Mockery's `alias:`
 * mock mode (which intercepts the class at the autoloader level) to avoid ANY
 * real network call to Stripe. This only works as long as `Stripe\PaymentMethod`
 * / `Stripe\Customer` have not yet been autoloaded elsewhere in the process, so
 * these tests must not be preceded (in the same PHP process) by a real Stripe
 * SDK class reference. Kept in their own file/class for exactly this reason.
 */
class StripeCustomerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_card_details_returns_masked_card_number_only(): void
    {
        $paymentMethod = Mockery::mock('alias:Stripe\PaymentMethod');
        $paymentMethod->shouldReceive('retrieve')
            ->once()
            ->with('pm_test_123')
            ->andReturn((object) [
                'billing_details' => (object) ['name' => 'Jane Borrower'],
                'card' => (object) [
                    'last4' => '4242',
                    // A real Stripe PaymentMethod object never actually exposes the
                    // full PAN, but we assert the service does not surface it even if present.
                    'number' => '4242424242424242',
                ],
            ]);

        $service = new StripeCustomerService;
        $result = $service->fetchCardDetails('pm_test_123');

        $this->assertSame('Jane Borrower', $result['name']);
        $this->assertSame('**** **** **** 4242', $result['card_no']);

        // Compliance invariant: never a 13-19 digit run (a full PAN) anywhere in the result.
        $this->assertDoesNotMatchRegularExpression('/\d{13,19}/', (string) $result['card_no']);
        $this->assertStringNotContainsString('4242424242424242', (string) $result['card_no']);
    }

    public function test_fetch_card_details_returns_nulls_when_card_missing(): void
    {
        $paymentMethod = Mockery::mock('alias:Stripe\PaymentMethod');
        $paymentMethod->shouldReceive('retrieve')
            ->once()
            ->with('pm_no_card')
            ->andReturn((object) [
                'billing_details' => (object) ['name' => null],
            ]);

        $service = new StripeCustomerService;
        $result = $service->fetchCardDetails('pm_no_card');

        $this->assertNull($result['name']);
        $this->assertNull($result['card_no']);
    }

    public function test_fetch_card_details_swallows_stripe_exceptions_and_returns_nulls(): void
    {
        $paymentMethod = Mockery::mock('alias:Stripe\PaymentMethod');
        $paymentMethod->shouldReceive('retrieve')
            ->once()
            ->with('pm_error')
            ->andThrow(new \Exception('Stripe API unreachable'));

        $service = new StripeCustomerService;
        $result = $service->fetchCardDetails('pm_error');

        $this->assertSame(['name' => null, 'card_no' => null], $result);
    }

    public function test_resolve_customer_uses_locally_stored_id_without_calling_stripe(): void
    {
        // No Stripe alias mock configured/expected here: passing a locally stored
        // customer ID must short-circuit before any Stripe call is made.
        $service = new StripeCustomerService;

        $result = $service->resolveCustomer('borrower@example.com', 'Jane Borrower', 'cus_local_123');

        $this->assertSame('cus_local_123', $result);
    }

    public function test_resolve_customer_extracts_id_from_stored_array_payload(): void
    {
        $service = new StripeCustomerService;

        $result = $service->resolveCustomer('borrower@example.com', 'Jane Borrower', [
            'customer_id' => 'cus_from_array',
            'name' => 'Jane Borrower',
            'card_no' => '**** **** **** 4242',
        ]);

        $this->assertSame('cus_from_array', $result);
    }
}
