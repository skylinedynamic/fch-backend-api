<?php

namespace Tests\Unit\Payment;

use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Stripe;
use Tests\TestCase;

/**
 * Which secret key the Stripe driver arms itself with.
 *
 * initialize() is the last step before money moves: whatever key it hands to
 * Stripe::setApiKey() is the account every subsequent call in that request lands
 * on. When the mode reaching it was wrong, this is where the wrong account got
 * chosen - so these pin the mapping from mode to key, and pin that an
 * unconfigured mode refuses rather than improvising.
 *
 * No network: initialize() only sets a key, it makes no request.
 */
#[CoversClass(StripePaymentDriver::class)]
class StripePaymentDriverTest extends TestCase
{
    private const SK_LIVE = 'sk_live_driver';
    private const SK_TEST = 'sk_test_driver';

    private array $config = [
        'sandbox' => ['publishable_key' => 'pk_test_driver', 'secret_key' => self::SK_TEST],
        'production' => ['publishable_key' => 'pk_live_driver', 'secret_key' => self::SK_LIVE],
    ];

    protected function tearDown(): void
    {
        Stripe::setApiKey(null);

        parent::tearDown();
    }

    #[Test]
    public function production_arms_the_live_secret_key(): void
    {
        (new StripePaymentDriver())->initialize($this->config, PaymentProvider::MODE_PRODUCTION);

        $this->assertSame(self::SK_LIVE, Stripe::getApiKey());
    }

    #[Test]
    public function sandbox_arms_the_test_secret_key(): void
    {
        (new StripePaymentDriver())->initialize($this->config, PaymentProvider::MODE_SANDBOX);

        $this->assertSame(self::SK_TEST, Stripe::getApiKey());
    }

    /**
     * The default matters: a caller that cannot determine a mode must land on the
     * account where a mistake is free, not the one where it is not.
     */
    #[Test]
    public function it_defaults_to_the_sandbox_key(): void
    {
        (new StripePaymentDriver())->initialize($this->config);

        $this->assertSame(self::SK_TEST, Stripe::getApiKey());
    }

    /**
     * A stray mode string must not be used as a lookup key, silently missing the
     * production block and falling through to whatever else matches.
     */
    #[Test]
    public function it_normalizes_the_mode_it_is_given(): void
    {
        (new StripePaymentDriver())->initialize($this->config, '  PRODUCTION  ');

        $this->assertSame(self::SK_LIVE, Stripe::getApiKey());
    }

    /**
     * Refusing is the correct outcome for a half-configured partner. Borrowing
     * the other mode's key would charge the wrong Stripe account, which is the
     * failure this whole area exists to prevent.
     */
    #[Test]
    public function it_refuses_a_mode_with_no_secret_key_rather_than_borrowing_one(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        (new StripePaymentDriver())->initialize([
            'sandbox' => ['secret_key' => self::SK_TEST],
            'production' => [],
        ], PaymentProvider::MODE_PRODUCTION);
    }

    /**
     * Configurations written before the sandbox/production split stored their
     * keys under mode-suffixed names, and must keep working.
     */
    #[Test]
    public function it_reads_a_legacy_mode_suffixed_secret(): void
    {
        (new StripePaymentDriver())->initialize([
            'stripe_secret_production' => self::SK_LIVE,
        ], PaymentProvider::MODE_PRODUCTION);

        $this->assertSame(self::SK_LIVE, Stripe::getApiKey());
    }
}
