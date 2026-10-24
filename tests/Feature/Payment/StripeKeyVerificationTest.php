<?php

namespace Tests\Feature\Payment;

use App\Http\Requests\Api\V1\FCH\Common\Setting\StorePartnerPaymentProviderRequest;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Support\Payment\StripeKeyVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stripe keys are checked with Stripe when they're saved.
 *
 * A key with the right prefix can still be wrong - a typo, or a key from
 * another Stripe account - and the first anyone heard of it was a payer told
 * "Invalid API Key provided" or "No such PaymentMethod". Saving now asks
 * Stripe: the secret key must be accepted, the publishable key must be
 * accepted, and a token made with the publishable key must be visible to the
 * secret key (same account).
 *
 * Only a definite "no" from Stripe refuses the save. Stripe being slow,
 * unreachable, rate-limiting or refusing a restricted key the permission to
 * look, never stops a partner saving - that would make a Stripe outage a
 * settings outage.
 */
#[CoversClass(StripeKeyVerifier::class)]
#[CoversClass(StorePartnerPaymentProviderRequest::class)]
class StripeKeyVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/common/partner-payment-providers';
    private const ACCOUNT = 'https://api.stripe.com/v1/account';
    private const TOKENS = 'https://api.stripe.com/v1/tokens';

    private Partner $host;
    private PaymentProvider $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);
        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        Passport::actingAs(User::create([
            'name' => 'Partner Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]));
    }

    private static function stripeError(int $status, string $code = 'api_key_invalid'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => 'Stripe says no']], $status);
    }

    /** Stripe answers as it would for keys of one account, with each step overridable. */
    private function fakeStripe(array $overrides = []): void
    {
        Http::fake(array_merge([
            self::ACCOUNT => Http::response(['id' => 'acct_123']),
            self::TOKENS => Http::response(['id' => 'tok_123']),
            self::TOKENS . '/tok_123' => Http::response(['id' => 'tok_123']),
        ], $overrides));
    }

    private function save(array $configuration, string $mode = PaymentProvider::MODE_PRODUCTION): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => $configuration,
        ]);
    }

    /** The API names a nested field's error by its dotted path, as one key. */
    private function assertFieldError(\Illuminate\Testing\TestResponse $response, string $field, string $message): void
    {
        $this->assertSame($message, $response->json('errors')[$field] ?? null);
    }

    private function live(string $publishable = 'pk_live_abc123', string $secret = 'sk_live_abc123'): array
    {
        return ['production' => ['publishable_key' => $publishable, 'secret_key' => $secret]];
    }

    #[Test]
    public function keys_stripe_accepts_from_one_account_are_saved(): void
    {
        $this->fakeStripe();

        $this->save($this->live())->assertOk();

        $this->assertSame('pk_live_abc123', PartnerPaymentProvider::first()->publishableKey());
    }

    #[Test]
    public function each_key_is_only_ever_sent_where_it_belongs(): void
    {
        $this->fakeStripe();

        $this->save($this->live())->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === self::ACCOUNT && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer sk_live_abc123'));
        Http::assertSent(fn (Request $r) => $r->url() === self::TOKENS && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer pk_live_abc123'));
        Http::assertSent(fn (Request $r) => $r->url() === self::TOKENS . '/tok_123' && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer sk_live_abc123'));
        // The secret key never goes to the token endpoint the publishable key uses.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer sk_live_abc123'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_secret_key_stripe_rejects_is_refused_and_nothing_is_saved(): void
    {
        $this->fakeStripe([self::ACCOUNT => self::stripeError(401)]);

        $this->save($this->live())
            ->assertStatus(422)
            ->tap(fn ($response) => $this->assertFieldError($response, 'configuration.production.secret_key', __('payment_method.stripe_secret_key_rejected', ['mode' => 'Production'])))
            ->assertJsonPath('message', __('payment_method.stripe_secret_key_rejected', ['mode' => 'Production']));

        $this->assertDatabaseCount('partner_payment_providers', 0);
    }

    #[Test]
    public function a_publishable_key_stripe_rejects_is_refused(): void
    {
        $this->fakeStripe([self::TOKENS => self::stripeError(401)]);

        $this->save($this->live())
            ->assertStatus(422)
            ->tap(fn ($response) => $this->assertFieldError($response, 'configuration.production.publishable_key', __('payment_method.stripe_publishable_key_rejected', ['mode' => 'Production'])));

        $this->assertDatabaseCount('partner_payment_providers', 0);
    }

    #[Test]
    public function keys_from_two_stripe_accounts_are_refused(): void
    {
        $this->fakeStripe([self::TOKENS . '/tok_123' => self::stripeError(404, 'resource_missing')]);

        $this->save($this->live())
            ->assertStatus(422)
            ->tap(fn ($response) => $this->assertFieldError($response, 'configuration.production.secret_key', __('payment_method.stripe_keys_different_accounts', ['mode' => 'Production'])));

        $this->assertDatabaseCount('partner_payment_providers', 0);
    }

    #[Test]
    public function a_key_that_is_plainly_not_a_stripe_key_is_refused_without_asking_stripe(): void
    {
        $this->fakeStripe();

        $this->save(['sandbox' => ['publishable_key' => 'hello-world', 'secret_key' => 'sk_test_abc123']], PaymentProvider::MODE_SANDBOX)
            ->assertStatus(422)
            ->tap(fn ($response) => $this->assertFieldError($response, 'configuration.sandbox.publishable_key', __('payment_method.stripe_publishable_key_rejected', ['mode' => 'Sandbox'])));

        Http::assertNothingSent();
    }

    // ---- Never a settings outage ---------------------------------------------

    #[Test]
    public function stripe_being_unreachable_does_not_stop_the_save(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: api.stripe.com'));

        $this->save($this->live())->assertOk();

        $this->assertDatabaseCount('partner_payment_providers', 1);
    }

    #[Test]
    public function a_stripe_server_error_or_rate_limit_does_not_stop_the_save(): void
    {
        $this->fakeStripe([self::ACCOUNT => Http::response([], 500), self::TOKENS => Http::response([], 429)]);

        $this->save($this->live())->assertOk();
    }

    #[Test]
    public function a_restricted_key_without_permission_to_look_does_not_stop_the_save(): void
    {
        $this->fakeStripe([
            self::ACCOUNT => self::stripeError(403, 'secret_key_required'),
            self::TOKENS . '/tok_123' => self::stripeError(403, 'secret_key_required'),
        ]);

        $this->save($this->live('pk_live_abc123', 'rk_live_abc123'))->assertOk();
    }

    // ---- What is checked ------------------------------------------------------

    #[Test]
    public function every_mode_with_keys_is_checked_not_only_the_active_one(): void
    {
        $this->fakeStripe([self::ACCOUNT => function (Request $r) {
            return $r->hasHeader('Authorization', 'Bearer sk_test_abc123') ? self::stripeError(401) : Http::response(['id' => 'acct_123']);
        }]);

        $this->save([
            'sandbox' => ['publishable_key' => 'pk_test_abc123', 'secret_key' => 'sk_test_abc123'],
            'production' => ['publishable_key' => 'pk_live_abc123', 'secret_key' => 'sk_live_abc123'],
        ])
            ->assertStatus(422)
            ->tap(fn ($response) => $this->assertFieldError($response, 'configuration.sandbox.secret_key', __('payment_method.stripe_secret_key_rejected', ['mode' => 'Sandbox'])));
    }

    #[Test]
    public function an_empty_mode_is_not_checked(): void
    {
        $this->fakeStripe();

        $this->save([
            'sandbox' => ['publishable_key' => null, 'secret_key' => null],
            'production' => ['publishable_key' => 'pk_live_abc123', 'secret_key' => 'sk_live_abc123'],
        ])->assertOk();

        Http::assertSentCount(3);
    }

    #[Test]
    public function keys_saved_before_are_not_checked_again(): void
    {
        $this->fakeStripe();
        $this->save($this->live())->assertOk();

        // Saving again with the same keys - say, to change the default - asks Stripe nothing.
        $this->save($this->live())->assertOk();

        Http::assertSentCount(3);
    }

    #[Test]
    public function a_changed_key_is_checked(): void
    {
        // Stripe accepts the saved secret key and rejects the new one.
        $this->fakeStripe([self::ACCOUNT => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_live_changed')
            ? self::stripeError(401)
            : Http::response(['id' => 'acct_123'])]);
        $this->save($this->live())->assertOk();

        $this->save($this->live('pk_live_abc123', 'sk_live_changed'))->assertStatus(422);

        $this->assertSame('sk_live_abc123', PartnerPaymentProvider::first()->secretKey());
    }

    #[Test]
    public function the_existing_mode_check_runs_first_and_stripe_is_not_asked(): void
    {
        Http::fake();

        $this->save(['production' => ['publishable_key' => 'pk_test_abc123', 'secret_key' => 'sk_test_abc123']])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function other_providers_never_ask_stripe(): void
    {
        Http::fake();
        $cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash Payment', 'status' => true]);

        $this->postJson(self::URL, [
            'payment_provider_id' => $cash->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'configuration' => [],
        ])->assertOk();

        Http::assertNothingSent();
    }
}
