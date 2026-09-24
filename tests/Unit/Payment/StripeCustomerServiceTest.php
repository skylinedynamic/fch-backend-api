<?php

namespace Tests\Unit\Payment;

use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Stripe;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Reusing a stored Stripe Customer, and knowing when not to.
 *
 * A Stripe Customer exists in exactly one account. Look one up with the other
 * account's key and Stripe reports it missing - so a stored `cus_` is only
 * reusable while the partner is still transacting in the mode it was created
 * under. Before the mode was recorded alongside it, a partner switching to
 * production carried their sandbox customer across and every charge failed.
 *
 * Stripe's HTTP layer is stubbed, so "did it re-resolve?" is answerable by
 * whether a lookup request was made at all.
 */
#[CoversClass(StripeCustomerService::class)]
class StripeCustomerServiceTest extends TestCase
{
    private FakeStripeHttpClient $stripe;
    private StripeCustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = FakeStripeHttpClient::install();
        $this->service = new StripeCustomerService();

        Stripe::setApiKey('sk_test_customer_service');
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();
        Stripe::setApiKey(null);

        parent::tearDown();
    }

    /**
     * The ordinary case: same mode, so the stored customer stands and Stripe is
     * never troubled.
     */
    #[Test]
    public function it_reuses_a_stored_customer_from_the_same_mode(): void
    {
        $customerId = $this->service->resolveCustomer('payer@example.test', 'A Payer', [
            'customer_id' => 'cus_stored',
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
        ], PaymentProvider::MODE_PRODUCTION);

        $this->assertSame('cus_stored', $customerId);
        $this->assertSame([], $this->stripe->requests, 'Stripe was contacted for a customer already known to be valid');
    }

    /**
     * The guard. A production charge must not be handed a sandbox customer - it
     * does not exist on that account, and the charge fails on a message about the
     * customer rather than the mode.
     */
    #[Test]
    public function it_discards_a_stored_customer_belonging_to_the_other_mode(): void
    {
        $customerId = $this->service->resolveCustomer('payer@example.test', 'A Payer', [
            'customer_id' => 'cus_from_sandbox',
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
        ], PaymentProvider::MODE_PRODUCTION);

        $this->assertNotSame('cus_from_sandbox', $customerId);
        $this->assertTrue($this->stripe->called('customers'), 'the stale customer was reused instead of re-resolved');
    }

    /**
     * Customers stored before the mode was recorded carry none. They predate the
     * guard and are overwhelmingly correct, so they are still trusted - the
     * alternative would re-resolve every legacy payer for no reason.
     */
    #[Test]
    public function it_trusts_a_stored_customer_that_records_no_mode(): void
    {
        $customerId = $this->service->resolveCustomer('payer@example.test', 'A Payer', [
            'customer_id' => 'cus_legacy',
        ], PaymentProvider::MODE_PRODUCTION);

        $this->assertSame('cus_legacy', $customerId);
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * A plain string is the oldest storage shape and carries no mode either.
     */
    #[Test]
    public function it_trusts_a_bare_string_customer_id(): void
    {
        $customerId = $this->service->resolveCustomer(
            'payer@example.test',
            'A Payer',
            'cus_bare_legacy',
            PaymentProvider::MODE_PRODUCTION,
        );

        $this->assertSame('cus_bare_legacy', $customerId);
    }

    /**
     * Callers that cannot state a mode keep the previous behaviour rather than
     * re-resolving everyone.
     */
    #[Test]
    public function it_keeps_the_stored_customer_when_no_mode_is_supplied(): void
    {
        $customerId = $this->service->resolveCustomer('payer@example.test', 'A Payer', [
            'customer_id' => 'cus_stored',
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
        ]);

        $this->assertSame('cus_stored', $customerId);
    }

    /**
     * With nothing stored, an existing customer is found by email before a
     * duplicate is created.
     */
    #[Test]
    public function it_finds_an_existing_customer_by_email_before_creating_one(): void
    {
        $this->stripe->willReturn('customers', [
            'id' => 'cus_found_by_email',
            'object' => 'customer',
            'email' => 'payer@example.test',
        ]);

        $customerId = $this->service->resolveCustomer('payer@example.test', 'A Payer', null, PaymentProvider::MODE_SANDBOX);

        $this->assertSame('cus_found_by_email', $customerId);
    }

    /**
     * Card details are read back for display; a failure there must not take a
     * payment down with it.
     */
    #[Test]
    public function it_reports_empty_card_details_rather_than_failing(): void
    {
        FakeStripeHttpClient::uninstall();

        $details = $this->service->fetchCardDetails('pm_does_not_exist');

        $this->assertSame(['name' => null, 'card_no' => null], $details);
    }
}
