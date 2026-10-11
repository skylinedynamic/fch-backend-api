<?php

namespace Tests\Unit\Payment;

use App\Models\PaymentMethod\PaymentProvider;
use App\Rules\StripeKeysMatchPaymentMode;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catching a mode mismatch at the configuration screen instead of at checkout.
 *
 * Stripe keys state their own account in their prefix, so test keys saved under
 * production are detectable the moment they are submitted. Without this check
 * the first sign of the mistake is a declined payment whose error names a token
 * rather than a setting, which is why the original occurrence was diagnosed as a
 * Connect problem rather than a wrong key.
 */
#[CoversClass(StripeKeysMatchPaymentMode::class)]
class StripeKeysMatchPaymentModeTest extends TestCase
{
    private function validate(array $configuration, ?string $provider = PaymentProvider::STRIPE): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['configuration' => $configuration],
            ['configuration' => [new StripeKeysMatchPaymentMode($provider)]]
        );
    }

    #[Test]
    public function it_rejects_test_keys_saved_under_production(): void
    {
        $validator = $this->validate([
            'production' => [
                'publishable_key' => 'pk_test_51AbC',
                'secret_key' => 'sk_test_51AbC',
            ],
        ]);

        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_rejects_live_keys_saved_under_sandbox(): void
    {
        $validator = $this->validate([
            'sandbox' => [
                'publishable_key' => 'pk_live_51AbC',
                'secret_key' => 'sk_live_51AbC',
            ],
        ]);

        $this->assertTrue($validator->fails());
    }

    /**
     * A restricted key is a real secret key and carries the same marker.
     */
    #[Test]
    public function it_rejects_a_restricted_key_from_the_wrong_account(): void
    {
        $validator = $this->validate([
            'production' => ['secret_key' => 'rk_test_51AbC'],
        ]);

        $this->assertTrue($validator->fails());
    }

    /**
     * The normal case: both blocks filled in, each with its own account's keys.
     * A partner keeps test keys in sandbox while running production, so the
     * blocks are checked against their own mode, never the active one.
     */
    #[Test]
    public function it_accepts_each_block_holding_its_own_modes_keys(): void
    {
        $validator = $this->validate([
            'sandbox' => [
                'publishable_key' => 'pk_test_51AbC',
                'secret_key' => 'sk_test_51AbC',
            ],
            'production' => [
                'publishable_key' => 'pk_live_51AbC',
                'secret_key' => 'sk_live_51AbC',
            ],
        ]);

        $this->assertFalse($validator->fails());
    }

    /**
     * The rule is about contradiction, not completeness - a half-filled
     * configuration is a normal intermediate save.
     */
    #[Test]
    public function it_ignores_blank_and_unprefixed_values(): void
    {
        $validator = $this->validate([
            'production' => [
                'publishable_key' => '',
                'secret_key' => null,
            ],
            'sandbox' => ['secret_key' => 'placeholder'],
        ]);

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_ignores_providers_whose_keys_carry_no_mode(): void
    {
        $validator = $this->validate(
            ['production' => ['secret_key' => 'sk_test_51AbC']],
            PaymentProvider::SQUARE
        );

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_accepts_a_flat_legacy_configuration_it_cannot_attribute(): void
    {
        $validator = $this->validate([
            'publishable_key' => 'pk_live_51AbC',
            'secret_key' => 'sk_live_51AbC',
        ]);

        $this->assertFalse($validator->fails());
    }
}
