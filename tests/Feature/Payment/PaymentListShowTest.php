<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Functional HTTP tests for payment listing/detail endpoints, ownership-scoped:
 *  - Borrower:   POST /api/v1/borrower/payments/fetch-payments, GET /api/v1/borrower/payments/{id}
 *  - Common/FCH: POST /api/v1/common/payments/fetch-payments, GET /api/v1/common/payments/{id}
 */
class PaymentListShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Borrower-scoped endpoints
    // ─────────────────────────────────────────────────────────────

    public function test_borrower_payments_list_only_returns_own_payments(): void
    {
        $borrower = Borrower::factory()->create();
        $otherBorrower = Borrower::factory()->create();

        $ownPayment = Payment::factory()->forBorrower($borrower)->succeeded()->create();
        Payment::factory()->forBorrower($otherBorrower)->succeeded()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson('/api/v1/borrower/payments/fetch-payments', []);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownPayment->id, $ids);
        foreach ($ids as $id) {
            $payment = Payment::find($id);
            $this->assertSame($borrower->id, $payment->borrower_id);
        }
    }

    public function test_borrower_cannot_view_another_borrowers_payment(): void
    {
        $borrower = Borrower::factory()->create();
        $otherBorrower = Borrower::factory()->create();
        $otherPayment = Payment::factory()->forBorrower($otherBorrower)->succeeded()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/borrower/payments/{$otherPayment->id}");

        $response->assertStatus(404);
    }

    public function test_borrower_can_view_own_payment(): void
    {
        $borrower = Borrower::factory()->create();
        $payment = Payment::factory()->forBorrower($borrower)->succeeded()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/borrower/payments/{$payment->id}");

        $response->assertOk();
        $response->assertJson(['status' => true]);
    }

    public function test_borrower_payments_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/borrower/payments/fetch-payments', []);

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────
    // Common/FCH-scoped endpoints (partner RBAC)
    // ─────────────────────────────────────────────────────────────

    public function test_non_host_partner_payments_list_excludes_other_partners_payments(): void
    {
        $myPartner = Partner::factory()->create();
        $otherPartner = Partner::factory()->create();

        $myBorrower = Borrower::factory()->create(['partner_id' => $myPartner->id]);
        $myPayment = Payment::factory()->forBorrower($myBorrower)->succeeded()->create();

        $otherBorrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $otherPayment = Payment::factory()->forBorrower($otherBorrower)->succeeded()->create();

        $user = User::factory()->partner($myPartner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson('/api/v1/common/payments/fetch-payments', []);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    public function test_host_partner_can_view_a_partners_own_payment_to_host(): void
    {
        // Per PaymentService::applyRbacScope, the host sees payments where a partner
        // is paying the host (is_partner_paying=true / entity_type=Partner) or its own
        // client payments — but NOT another non-host partner's client-level payments
        // (see test_host_partner_cannot_view_other_partners_client_payment below).
        $host = Partner::factory()->host()->create();
        $otherPartner = Partner::factory()->create();
        $payment = Payment::factory()->forPartner($otherPartner)->succeeded()->create();

        $hostUser = User::factory()->partner($host)->create();
        $token = $hostUser->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/common/payments/{$payment->id}");

        $response->assertOk();
        $response->assertJson(['status' => true]);
    }

    public function test_host_partner_cannot_view_other_partners_client_payment(): void
    {
        // Documents the explicit "Host CANNOT see non-host partners' client
        // payments!" rule encoded in PaymentService::applyRbacScope.
        $host = Partner::factory()->host()->create();
        $otherPartner = Partner::factory()->create();
        $otherBorrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $payment = Payment::factory()->forBorrower($otherBorrower)->succeeded()->create();

        $hostUser = User::factory()->partner($host)->create();
        $token = $hostUser->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/common/payments/{$payment->id}");

        $response->assertStatus(404);
    }

    public function test_non_host_partner_cannot_view_other_partners_client_payment(): void
    {
        $myPartner = Partner::factory()->create();
        $otherPartner = Partner::factory()->create();
        $otherBorrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $payment = Payment::factory()->forBorrower($otherBorrower)->succeeded()->create();

        $user = User::factory()->partner($myPartner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/common/payments/{$payment->id}");

        $response->assertStatus(404);
    }
}
