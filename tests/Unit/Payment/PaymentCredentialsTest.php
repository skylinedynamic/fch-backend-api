<?php

namespace Tests\Unit\Payment;

use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Support\Payment\PaymentCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which credentials a partner's payment configuration resolves to.
 *
 * These exist because the two halves of a card payment once disagreed. The
 * publishable key sent to the browser was resolved from the `payment_mode`
 * column and came back `production`; the secret key used to charge was resolved
 * from a `payment_mode` entry inside the configuration JSON, which the save path
 * strips, so it silently defaulted to `sandbox`. Cards were tokenized live and
 * charged against test, and Stripe rejected every one with "No such
 * PaymentMethod" - an error naming the token, saying nothing about modes.
 *
 * So the case that matters most is the first one below: a linkage whose column
 * says production and whose JSON says nothing must resolve production, for the
 * secret key and the publishable key alike.
 *
 * Linkages are built in memory; resolution reads only their own attributes.
 */
#[CoversClass(PaymentCredentials::class)]
#[CoversClass(PartnerPaymentProvider::class)]
class PaymentCredentialsTest extends TestCase
{
    /**
     * A linkage as the settings screen saves it: mode in the column, credentials
     * nested per mode, and no `payment_mode` anywhere in the JSON.
     */
    private function linkage(string $columnMode, ?array $configuration = null): PartnerPaymentProvider
    {
        $linkage = new PartnerPaymentProvider();
        $linkage->payment_mode = $columnMode;
        $linkage->configuration = $configuration ?? [
            'sandbox' => [
                'publishable_key' => 'pk_test_sandbox',
                'secret_key' => 'sk_test_sandbox',
            ],
            'production' => [
                'publishable_key' => 'pk_live_production',
                'secret_key' => 'sk_live_production',
            ],
        ];

        return $linkage;
    }

    #[Test]
    public function it_resolves_production_from_the_column_when_the_json_is_silent(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION);

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->paymentMode());
        $this->assertSame('sk_live_production', $linkage->secretKey());
        $this->assertSame('pk_live_production', $linkage->publishableKey());
    }

    /**
     * The regression, stated directly: the key the browser gets and the key the
     * server charges with must come from the same Stripe account.
     */
    #[Test]
    public function the_publishable_and_secret_keys_always_come_from_the_same_mode(): void
    {
        foreach ([PaymentProvider::MODE_SANDBOX, PaymentProvider::MODE_PRODUCTION] as $mode) {
            $linkage = $this->linkage($mode);

            $expected = $mode === PaymentProvider::MODE_PRODUCTION ? 'live' : 'test';

            $this->assertStringContainsString($expected, $linkage->publishableKey());
            $this->assertStringContainsString($expected, $linkage->secretKey());
        }
    }

    #[Test]
    public function it_defaults_to_sandbox_when_the_column_is_empty(): void
    {
        $this->assertSame(PaymentProvider::MODE_SANDBOX, $this->linkage('')->paymentMode());
        $this->assertSame(PaymentProvider::MODE_SANDBOX, PaymentCredentials::mode(null, []));
    }

    /**
     * An unrecognized value must not be read as production - defaulting the
     * permissive way would let a typo move real money.
     */
    #[Test]
    public function it_treats_an_unrecognized_mode_as_sandbox(): void
    {
        $this->assertSame(PaymentProvider::MODE_SANDBOX, PaymentCredentials::mode('LIVE', []));
        $this->assertSame(PaymentProvider::MODE_SANDBOX, PaymentCredentials::mode('prod', []));
    }

    #[Test]
    public function it_normalizes_casing_and_surrounding_whitespace(): void
    {
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, PaymentCredentials::mode(' Production ', []));
    }

    /**
     * Rows written before the column existed still carry the mode inline, and
     * must keep resolving the way they always did.
     */
    #[Test]
    public function it_falls_back_to_the_json_for_rows_predating_the_column(): void
    {
        $this->assertSame(
            PaymentProvider::MODE_PRODUCTION,
            PaymentCredentials::mode(null, ['payment_mode' => PaymentProvider::MODE_PRODUCTION])
        );
    }

    #[Test]
    public function the_column_wins_over_a_stale_json_entry(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'production' => ['secret_key' => 'sk_live_production'],
        ]);

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->paymentMode());
        $this->assertSame('sk_live_production', $linkage->secretKey());
    }

    /**
     * Legacy flat configurations have no per-mode nesting to select from.
     */
    #[Test]
    public function it_reads_flat_legacy_configurations(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'publishable_key' => 'pk_live_flat',
            'secret_key' => 'sk_live_flat',
        ]);

        $this->assertSame('sk_live_flat', $linkage->secretKey());
        $this->assertSame('pk_live_flat', $linkage->publishableKey());
    }

    #[Test]
    public function it_reads_provider_prefixed_legacy_key_names(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'stripe_key_production' => 'pk_live_prefixed',
            'stripe_secret_production' => 'sk_live_prefixed',
        ]);

        $this->assertSame('sk_live_prefixed', $linkage->secretKey());
        $this->assertSame('pk_live_prefixed', $linkage->publishableKey());
    }

    /**
     * An unconfigured mode reports null rather than borrowing the other mode's
     * key, so the caller can say the partner cannot transact yet.
     */
    #[Test]
    public function it_returns_null_when_the_active_mode_has_no_credentials(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'sandbox' => [
                'publishable_key' => 'pk_test_sandbox',
                'secret_key' => 'sk_test_sandbox',
            ],
            'production' => [],
        ]);

        $this->assertNull($linkage->secretKey());
        $this->assertNull($linkage->publishableKey());
    }

    /**
     * The cross-account leak this class exists to prevent, in its subtlest form.
     *
     * A part-migrated row can carry both per-mode blocks and a leftover bare
     * `secret_key` from before the split. If production's own block is missing a
     * key, falling through to that bare one would charge with a credential that
     * belongs to nobody in particular - and in practice to the other account.
     * Better to report the mode unconfigured.
     */
    #[Test]
    public function it_never_borrows_a_bare_legacy_key_once_modes_are_separated(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'secret_key' => 'sk_test_leftover_flat',
            'publishable_key' => 'pk_test_leftover_flat',
            'sandbox' => [
                'publishable_key' => 'pk_test_sandbox',
                'secret_key' => 'sk_test_sandbox',
            ],
            'production' => [],
        ]);

        $this->assertNull($linkage->secretKey());
        $this->assertNull($linkage->publishableKey());
    }

    /**
     * The mode-suffixed legacy names say which account they belong to, so they
     * stay readable alongside the per-mode blocks.
     */
    #[Test]
    public function it_still_reads_mode_suffixed_legacy_names_alongside_blocks(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'sandbox' => ['secret_key' => 'sk_test_sandbox'],
            'production' => [],
            'stripe_secret_production' => 'sk_live_suffixed',
            'stripe_key_production' => 'pk_live_suffixed',
        ]);

        $this->assertSame('sk_live_suffixed', $linkage->secretKey());
        $this->assertSame('pk_live_suffixed', $linkage->publishableKey());
    }

    /**
     * Callers have historically seen stdClass and null out of this column.
     */
    #[Test]
    public function it_accepts_a_configuration_that_is_not_an_array(): void
    {
        $this->assertSame([], PaymentCredentials::toArray(null));
        $this->assertSame([], PaymentCredentials::toArray('not a config'));
        $this->assertSame(
            ['secret_key' => 'sk_live_object'],
            PaymentCredentials::toArray((object) ['secret_key' => 'sk_live_object'])
        );
    }

    #[Test]
    public function it_exposes_provider_specific_credentials_for_the_active_mode(): void
    {
        $linkage = $this->linkage(PaymentProvider::MODE_PRODUCTION, [
            'sandbox' => ['app_id' => 'sandbox-sq-app-id', 'access_token' => 'EAAAEO_sandbox'],
            'production' => ['app_id' => 'sq-app-id', 'access_token' => 'EAAAE_production'],
        ]);

        $this->assertSame(
            ['app_id' => 'sq-app-id', 'access_token' => 'EAAAE_production'],
            $linkage->credentials()
        );
    }
}
