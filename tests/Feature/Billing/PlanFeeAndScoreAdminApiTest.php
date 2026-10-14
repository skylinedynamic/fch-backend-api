<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
use App\Http\Controllers\Api\V1\FCH\Admin\PlanFee\PlanFeeController;
use App\Http\Controllers\Api\V1\FCH\Admin\ScoreConfiguration\ScoreConfigurationController;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Host-only platform settings: plan fees (and pushing a new price onto
 * existing subscriptions), the scoring model, and demo requests.
 */
#[CoversClass(PlanFeeController::class)]
#[CoversClass(ScoreConfigurationController::class)]
#[CoversClass(DemoRequestController::class)]
class PlanFeeAndScoreAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function plan(array $attributes): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
            'total_amount' => 49,
        ], $attributes));
    }

    private function seat(SubscriptionPlan $plan, Partner $partner): Subscription
    {
        $employee = Employee::find($this->makeStaffUser($partner)->entity_id);

        return Subscription::create([
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'subscription_plan_id' => $plan->id,
            'partner_type' => $plan->partner_type,
            'installment_count' => 1,
            'payable_amount' => 49,
            'total_subscription_amount' => 49,
            'monthly_platform_fee' => 49,
            'per_seat_amount' => 49,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function plan_fees_are_host_only(): void
    {
        $plan = $this->plan(['group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT]);
        $this->actingAsUser($this->makeStaffUser($this->makeWhiteLabelPartner()));

        $this->postJson('/api/v1/admin/plan-fee/fetch-subscription-plans')->assertForbidden();
        $this->putJson("/api/v1/admin/plan-fee/update-subscription-plan/{$plan->id}", ['total_amount' => 1])->assertForbidden();
    }

    #[Test]
    public function the_host_lists_plans_by_group_and_changes_a_price(): void
    {
        $plan = $this->plan(['group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/plan-fee/fetch-subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.' . SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT . '.0.id', $plan->id);
        $this->putJson("/api/v1/admin/plan-fee/update-subscription-plan/{$plan->id}", ['total_amount' => 59])->assertOk();
        $this->putJson("/api/v1/admin/plan-fee/update-subscription-plan/{$plan->id}", ['total_amount' => -1])->assertUnprocessable();

        $this->assertEquals(59, $plan->fresh()->total_amount);
    }

    /**
     * Defect: pushing a new seat price onto existing subscriptions updated
     * every User/Employee seat subscription on the platform, whichever plan
     * it was on - seat plans are per partner type, so repricing the
     * white-label seat also repriced every certified partner's seats.
     */
    #[Test]
    public function a_new_seat_price_reaches_only_that_plans_seats(): void
    {
        $whiteLabelSeats = $this->plan(['group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT, 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL]);
        $certifiedSeats = $this->plan(['group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT, 'partner_type' => Partner::PARTNER_TYPE_CERTIFIED]);
        $mine = $this->seat($whiteLabelSeats, $this->makeWhiteLabelPartner());
        $theirs = $this->seat($certifiedSeats, $this->makeCertifiedPartner());
        $this->actingAsUser($this->hostAdmin);

        $this->putJson("/api/v1/admin/plan-fee/update-subscription-plan/{$whiteLabelSeats->id}", [
            'total_amount' => 59,
            'include_existing_subscription' => true,
        ])->assertOk();

        $this->assertEquals(59, $mine->fresh()->monthly_platform_fee);
        $this->assertEquals(49, $theirs->fresh()->monthly_platform_fee);
        $this->assertEquals(49, $theirs->fresh()->per_seat_amount);
    }

    #[Test]
    public function the_scoring_model_is_read_and_saved_by_the_host_team(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->getJson('/api/v1/admin/app-configuration/score')->assertOk();
        $this->putJson('/api/v1/admin/app-configuration/score', [])->assertOk();
        $this->putJson('/api/v1/admin/app-configuration/score', ['funding_readiness' => ['factors' => [['weight' => 150]]]])
            ->assertUnprocessable();

        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));
        $this->getJson('/api/v1/admin/app-configuration/score')->assertForbidden();
    }

    #[Test]
    public function a_demo_request_is_recorded_and_listed(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->postJson('/api/v1/admin/demo-requests/store', [
            'customer_name' => 'Prospect LLC',
            'email' => 'buyer@prospect.example.org',
            'demo_start' => '2026-11-01',
            'demo_end' => '2026-11-14',
        ])->assertOk();
        $this->postJson('/api/v1/admin/demo-requests/store', ['customer_name' => 'X', 'demo_start' => '2026-11-14', 'demo_end' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('demo_end');

        $this->getJson('/api/v1/admin/demo-requests')->assertOk()->assertJsonPath('data.data.0.customer_name', 'Prospect LLC');
    }
}
