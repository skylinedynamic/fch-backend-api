<?php

namespace Tests\Unit\Payment;

use App\Models\PaymentMethod\PaymentProvider;
use App\Rules\StripeKeysMatchPaymentMode;
use App\Support\Payment\PaymentCredentials;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E-014: partner 1's sandbox Stripe block had its keys swapped - an `sk_test_`
 * secret in `publishable_key` and a `pk_test_` key in `secret_key`. The mode
 * check passed (both are test keys), so it saved. The publishable key is what
 * the borrower portal hands to Stripe.js, so the secret key reached the browser,
 * and every card payment failed.
 *
 * Two guards: the configuration screen rejects a key in the wrong field, and
 * the publishable-key accessor never returns a secret/restricted key.
 */
class StripeKeyFieldsSwappedTest extends TestCase
{
    private function validate(array $configuration): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['configuration' => $configuration],
            ['configuration' => [new StripeKeysMatchPaymentMode(PaymentProvider::STRIPE)]]
        );
    }

    #[Test]
    public function it_rejects_a_secret_key_saved_as_the_publishable_key(): void
    {
        $validator = $this->validate([
            'sandbox' => ['publishable_key' => 'sk_test_51AbC', 'secret_key' => 'pk_test_51AbC'],
        ]);

        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_rejects_a_restricted_key_saved_as_the_publishable_key(): void
    {
        $this->assertTrue($this->validate([
            'production' => ['publishable_key' => 'rk_live_51AbC', 'secret_key' => 'sk_live_51AbC'],
        ])->fails());
    }

    #[Test]
    public function it_rejects_a_publishable_key_saved_as_the_secret_key(): void
    {
        $this->assertTrue($this->validate([
            'production' => ['publishable_key' => 'pk_live_51AbC', 'secret_key' => 'pk_live_51XyZ'],
        ])->fails());
    }

    #[Test]
    public function correctly_placed_keys_still_pass(): void
    {
        $this->assertFalse($this->validate([
            'sandbox' => ['publishable_key' => 'pk_test_51AbC', 'secret_key' => 'sk_test_51AbC'],
            'production' => ['publishable_key' => 'pk_live_51AbC', 'secret_key' => 'rk_live_51AbC'],
        ])->fails());
    }

    #[Test]
    public function a_secret_key_is_never_returned_as_the_publishable_key(): void
    {
        $config = ['sandbox' => ['publishable_key' => 'sk_test_51AbC', 'secret_key' => 'pk_test_51AbC']];

        $this->assertNull(PaymentCredentials::publishableKey($config, PaymentProvider::MODE_SANDBOX));
    }

    #[Test]
    public function a_correct_publishable_key_is_returned_as_before(): void
    {
        $config = ['sandbox' => ['publishable_key' => 'pk_test_51AbC', 'secret_key' => 'sk_test_51AbC']];

        $this->assertSame('pk_test_51AbC', PaymentCredentials::publishableKey($config, PaymentProvider::MODE_SANDBOX));
    }
}
