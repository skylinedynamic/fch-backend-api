<?php

namespace Tests\Feature\Billing;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Functional HTTP tests for the borrower billing endpoints:
 *  GET  /v1/borrower/billing-details
 *  POST /v1/borrower/update-default-payment-method
 *  POST /v1/borrower/cancel-subscription
 */
class BorrowerBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function actingAsBorrower(?Borrower $borrower = null): array
    {
        $borrower ??= Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        return [$borrower, $user, $token];
    }

    public function test_billing_details_returns_200_for_authenticated_borrower(): void
    {
        [$borrower, , $token] = $this->actingAsBorrower();
        Subscription::factory()->forBorrower($borrower)->active()->create();

        $response = $this->withToken($token)->getJson('/api/v1/borrower/billing-details');

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => ['borrower', 'subscription', 'upcoming_billing', 'recent_payments'],
        ]);
    }

    public function test_billing_details_is_scoped_to_the_authenticated_borrowers_own_data(): void
    {
        [$borrower, , $token] = $this->actingAsBorrower();
        Subscription::factory()->forBorrower($borrower)->active()->create();

        $response = $this->withToken($token)->getJson('/api/v1/borrower/billing-details');

        $response->assertOk();
        $this->assertSame($borrower->id, $response->json('data.borrower.id'));
    }

    public function test_billing_details_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/borrower/billing-details');

        $response->assertStatus(401);
    }

    public function test_billing_details_rejects_non_borrower_users(): void
    {
        $partner = Partner::factory()->create();
        $user = User::factory()->partner($partner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson('/api/v1/borrower/billing-details');

        $response->assertStatus(403);
    }

    public function test_update_default_payment_method_requires_provider_name(): void
    {
        [, , $token] = $this->actingAsBorrower();

        $response = $this->withToken($token)->postJson('/api/v1/borrower/update-default-payment-method', []);

        $response->assertStatus(422);
        $response->assertJson(['status' => false]);
    }

    public function test_update_default_payment_method_sets_non_stripe_provider_directly(): void
    {
        [$borrower, , $token] = $this->actingAsBorrower();
        PaymentProvider::factory()->create([
            'name' => PaymentProvider::CASH,
            'display_name' => 'Cash',
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/borrower/update-default-payment-method', [
            'provider_name' => PaymentProvider::CASH,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame(PaymentProvider::CASH, $borrower->fresh()->default_payment_method);
        $this->assertNull($borrower->fresh()->stripe_customer_id);
    }

    public function test_cancel_subscription_cancels_the_borrowers_latest_subscription(): void
    {
        [$borrower, , $token] = $this->actingAsBorrower();
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();

        $response = $this->withToken($token)->postJson('/api/v1/borrower/cancel-subscription', []);

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_cancel_subscription_returns_error_when_already_cancelled(): void
    {
        [$borrower, , $token] = $this->actingAsBorrower();
        Subscription::factory()->forBorrower($borrower)->cancelled()->create();

        $response = $this->withToken($token)->postJson('/api/v1/borrower/cancel-subscription', []);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_cancel_subscription_returns_404_when_borrower_has_no_subscription(): void
    {
        [, , $token] = $this->actingAsBorrower();

        $response = $this->withToken($token)->postJson('/api/v1/borrower/cancel-subscription', []);

        $response->assertStatus(404);
        $response->assertJson(['status' => false]);
    }
}
