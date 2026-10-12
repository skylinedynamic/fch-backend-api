<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Common\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Http\Controllers\Api\V1\FCH\Common\Compensation\CompensationController;
use App\Http\Controllers\Api\V1\FCH\Common\Department\DepartmentController;
use App\Http\Controllers\Api\V1\FCH\Common\MySubscription\MySubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Common\Statistics\StatisticsController;
use App\Http\Controllers\Api\V1\FCH\Common\Subscription\SubscriptionController;
use App\Http\Controllers\Api\V1\FCH\Common\SubscriptionPlan\SubscriptionPlanController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Compensation\CompensationSetting;
use App\Models\Department\Department;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner's own account screens under /common: departments, plans, its
 * own subscription, its clients' subscriptions, commissions, compensation
 * setups, the profile stats and the team lookups. Each is scoped to the
 * caller's partner; the host team sees across partners where the screen is
 * a platform view.
 */
#[CoversClass(DepartmentController::class)]
#[CoversClass(SubscriptionPlanController::class)]
#[CoversClass(MySubscriptionController::class)]
#[CoversClass(SubscriptionController::class)]
#[CoversClass(CommissionController::class)]
#[CoversClass(CompensationController::class)]
#[CoversClass(StatisticsController::class)]
#[CoversClass(CommonController::class)]
class PartnerAccountApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->admin = $this->makePartnerAdmin($this->partner);
    }

    private function plan(array $attributes = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'entity_type' => Partner::class,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
            'total_amount' => 500,
        ], $attributes));
    }

    private function subscribe($entity, array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 500,
            'total_subscription_amount' => 500,
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function commission(Partner $partner): Commission
    {
        $borrower = $this->makeBorrower($partner);
        $application = FundingApplication::forceCreate(['borrower_id' => $borrower->id, 'status' => FundingApplication::CLOSED_COMPLETED]);

        return Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'total_commission' => 10,
            'admin_commission' => 4,
            'partner_commission' => 6,
            'total_commission_amount' => 5000,
            'admin_commission_amount' => 2000,
            'partner_commission_amount' => 3000,
            'commission_status' => Commission::STATUS_PROCESSING,
            'payment_status' => Commission::PAYMENT_STATUS_PENDING,
        ]);
    }

    // ── Departments ──────────────────────────────────────────────────

    /**
     * Defect: show eager-loaded an "employees" relation the model does not
     * have (employees name their department as text), so every department's
     * details failed with a 400.
     */
    #[Test]
    public function a_partner_manages_its_own_departments(): void
    {
        $this->actingAsUser($this->admin);

        $id = $this->postJson('/api/v1/common/departments', ['name' => 'Sales'])->assertOk()->json('data.id');
        $this->assertSame($this->partner->id, Department::find($id)->partner_id);

        $this->postJson('/api/v1/common/departments/fetch-departments')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/common/departments/{$id}")->assertOk()->assertJsonPath('data.name', 'Sales');
        $this->deleteJson("/api/v1/common/departments/{$id}")->assertOk();
        $this->assertNull(Department::find($id));
    }

    #[Test]
    public function another_partners_department_is_out_of_reach(): void
    {
        $theirs = Department::create(['name' => 'Theirs', 'partner_id' => $this->otherPartner->id]);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/departments/fetch-departments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/common/departments/{$theirs->id}")->assertStatus(400);
        $this->deleteJson("/api/v1/common/departments/{$theirs->id}")->assertStatus(400);
        $this->assertNotNull($theirs->fresh());
    }

    // ── Plans ────────────────────────────────────────────────────────

    #[Test]
    public function plans_list_the_active_ones_for_each_audience(): void
    {
        $partnerPlan = $this->plan(['enrollment_type_slug' => 'pay-in-full']);
        $this->plan(['status' => false]);
        $borrowerPlan = $this->plan(['entity_type' => Borrower::class, 'is_partner_paying' => false]);
        $this->actingAsUser($this->admin);

        $this->assertSame([$partnerPlan->id], collect($this->getJson('/api/v1/common/partner-plans')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$borrowerPlan->id], collect($this->getJson('/api/v1/common/borrower-plans')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/common/partner-plans/pay-in-full')->assertOk()->assertJsonPath('data.id', $partnerPlan->id);
        $this->getJson("/api/v1/common/partner-plans/{$partnerPlan->id}")->assertOk();
        $this->getJson('/api/v1/common/partner-plans/no-such-plan')->assertNotFound();
    }

    // ── My subscription ──────────────────────────────────────────────

    #[Test]
    public function a_partner_reads_its_own_subscription(): void
    {
        $mine = $this->subscribe($this->partner);
        $this->subscribe($this->otherPartner);
        $this->actingAsUser($this->admin);

        $this->getJson('/api/v1/common/my-subscription')
            ->assertOk()
            ->assertJsonPath('data.partner.id', $this->partner->id)
            ->assertJsonPath('data.subscription.id', $mine->id);
    }

    #[Test]
    public function the_host_has_no_subscription_screen(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson('/api/v1/common/my-subscription')->assertForbidden();
    }

    #[Test]
    public function a_partner_cancels_its_own_subscription_once(): void
    {
        $mine = $this->subscribe($this->partner);
        $theirs = $this->subscribe($this->otherPartner);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/my-subscription/cancel-subscription')->assertOk();
        $this->postJson('/api/v1/common/my-subscription/cancel-subscription')->assertStatus(400);

        $this->assertSame(Subscription::STATUS_CANCELLED, $mine->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $theirs->fresh()->status);
    }

    /** A gateway the platform has and the host - whom partners pay - has switched on (or off). */
    private function hostOffersCash(bool $switchedOn = true): PaymentProvider
    {
        $cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        app(\App\Services\FCH\Common\Setting\PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $cash->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => $switchedOn,
            'configuration' => [],
        ]);

        return $cash;
    }

    #[Test]
    public function a_partner_switches_its_default_payment_method_off_stripe(): void
    {
        $this->hostOffersCash();
        $this->partner->update(['default_payment_method' => PaymentProvider::STRIPE, 'stripe_customer_id' => ['customer_id' => 'cus_1']]);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/my-subscription/update-payment-method', ['provider_name' => PaymentProvider::CASH])
            ->assertOk()
            ->assertJsonPath('data.default_payment_method', PaymentProvider::CASH);
        $this->assertNull($this->partner->fresh()->stripe_customer_id);
    }

    /**
     * Defect (O25): any provider_name string was stored as the partner's
     * default, whether or not the host - whom the partner pays - offers it.
     */
    #[Test]
    public function a_partners_default_must_be_a_gateway_the_host_has_switched_on(): void
    {
        $this->hostOffersCash(switchedOn: false);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/my-subscription/update-payment-method', ['provider_name' => 'Bitcoin'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_name');
        $this->postJson('/api/v1/common/my-subscription/update-payment-method', ['provider_name' => PaymentProvider::CASH])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_name');

        $this->assertNotSame(PaymentProvider::CASH, $this->partner->fresh()->default_payment_method);
    }

    /**
     * Defect: when Stripe refused the new card the error was only logged,
     * then the partner's saved card was overwritten with nothing and the call
     * reported success - the partner was left with no card on file.
     */
    #[Test]
    public function a_card_stripe_refuses_leaves_the_saved_card_in_place(): void
    {
        $saved = ['customer_id' => 'cus_saved', 'name' => 'Visa', 'card_no' => '4242', 'payment_mode' => 'test'];
        $this->partner->update(['default_payment_method' => PaymentProvider::STRIPE, 'stripe_customer_id' => $saved]);
        $this->partialMock(Payment::class)->shouldReceive('configureStripeForPartner')->andReturn((object) ['payment_mode' => 'test']);
        $this->mock(StripeCustomerService::class)->shouldReceive('resolveCustomer')->andThrow(new \Exception('Your card was declined.'));
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/my-subscription/update-payment-method', [
            'provider_name' => PaymentProvider::STRIPE,
            'payment_method_id' => 'pm_declined',
        ])->assertStatus(422)->assertJsonPath('status', false);

        $this->assertSame($saved, $this->partner->fresh()->stripe_customer_id);
    }

    // ── Clients' subscriptions ───────────────────────────────────────

    #[Test]
    public function a_partner_sees_only_its_clients_subscriptions(): void
    {
        $client = $this->subscribe($this->makeBorrower($this->partner));
        $own = $this->subscribe($this->partner);
        $otherClient = $this->subscribe($this->makeBorrower($this->otherPartner));
        $this->actingAsUser($this->admin);

        $ids = collect($this->postJson('/api/v1/common/subscriptions/fetch-all')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$client->id], $ids);

        $this->getJson("/api/v1/common/subscriptions/{$client->id}")->assertOk();
        $this->getJson("/api/v1/common/subscriptions/{$own->id}")->assertNotFound();
        $this->getJson("/api/v1/common/subscriptions/{$otherClient->id}")->assertNotFound();
    }

    #[Test]
    public function the_host_sees_every_subscription(): void
    {
        $a = $this->subscribe($this->makeBorrower($this->partner));
        $b = $this->subscribe($this->otherPartner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $ids = collect($this->postJson('/api/v1/common/subscriptions/fetch-all')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    #[Test]
    public function the_borrower_fee_comes_from_the_fixed_enrollment_plan(): void
    {
        $this->plan([
            'entity_type' => Borrower::class,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            'total_amount' => 199,
        ]);
        $this->actingAsUser($this->admin);

        $this->getJson('/api/v1/common/subscriptions/partner-borrower-fee')->assertOk()->assertJsonFragment(['status' => true]);
        $this->assertStringContainsString('199', $this->getJson('/api/v1/common/subscriptions/partner-borrower-fee')->getContent());
    }

    // ── Upcoming billing ─────────────────────────────────────────────

    #[Test]
    public function a_partners_upcoming_billing_is_its_own_clients_only(): void
    {
        $due = ['subscription_end_date' => now()->addDays(3)->toDateString()];
        $client = $this->subscribe($this->makeBorrower($this->partner), $due);
        $ownFee = $this->subscribe($this->partner, $due);
        $otherClient = $this->subscribe($this->makeBorrower($this->otherPartner), $due);
        $this->actingAsUser($this->admin);

        $ids = collect($this->postJson('/api/v1/common/billing/fetch-upcoming')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$client->id], $ids);

        $this->getJson("/api/v1/common/billing/{$client->id}")->assertOk()->assertJsonPath('data.entity_id', fn ($id) => (int) $id === (int) $client->entity_id);
        $this->getJson("/api/v1/common/billing/{$ownFee->id}")->assertNotFound();
        $this->getJson("/api/v1/common/billing/{$otherClient->id}")->assertNotFound();
    }

    #[Test]
    public function the_hosts_upcoming_billing_holds_partner_fees_but_not_partners_clients(): void
    {
        $due = ['subscription_end_date' => now()->addDays(3)->toDateString()];
        $partnerFee = $this->subscribe($this->partner, $due);
        $hostClient = $this->subscribe($this->makeBorrower($this->host), $due);
        $partnersClient = $this->subscribe($this->makeBorrower($this->partner), $due);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $ids = collect($this->postJson('/api/v1/common/billing/fetch-upcoming')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$partnerFee->id, $hostClient->id], $ids);
        $this->assertNotContains($partnersClient->id, $ids);
    }

    // ── Commissions ──────────────────────────────────────────────────

    #[Test]
    public function a_partner_lists_only_its_own_commissions(): void
    {
        $mine = $this->commission($this->partner);
        $theirs = $this->commission($this->otherPartner);
        $this->actingAsUser($this->admin);

        $this->assertSame([$mine->id], collect($this->postJson('/api/v1/common/commissions')->assertOk()->json('data'))->pluck('id')->all());
        // Naming another partner does not widen the list.
        $this->assertSame([], collect($this->postJson('/api/v1/common/commissions', ['partner_id' => $this->otherPartner->id])->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/common/commissions/{$theirs->id}")->assertNotFound();
    }

    #[Test]
    public function the_host_lists_every_commission(): void
    {
        $a = $this->commission($this->partner);
        $b = $this->commission($this->otherPartner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($this->postJson('/api/v1/common/commissions')->assertOk()->json('data'))->pluck('id')->all());
    }

    // ── Compensation ─────────────────────────────────────────────────

    #[Test]
    public function a_partner_sets_and_reads_a_team_members_compensation(): void
    {
        $member = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->admin);
        $target = ['entity_type' => 'user', 'entity_id' => $member->id];

        $this->postJson('/api/v1/common/compensations', $target + [
            'paid_by' => CompensationSetting::PAID_BY_HOURLY,
            'compensations' => [['amount' => 25]],
        ])->assertOk();

        $this->postJson('/api/v1/common/compensations/fetch-compensation', $target)->assertOk();
        // A team member's setup is filed under their employee record.
        $this->assertDatabaseHas('compensation_settings', [
            'entity_type' => Employee::class,
            'entity_id' => $member->entity_id,
            'paid_by' => CompensationSetting::PAID_BY_HOURLY,
        ]);
    }

    #[Test]
    public function another_partners_team_members_compensation_is_out_of_reach(): void
    {
        $theirs = $this->makeStaffUser($this->otherPartner);
        $this->actingAsUser($this->admin);
        $target = ['entity_type' => 'user', 'entity_id' => $theirs->id];

        $this->postJson('/api/v1/common/compensations/fetch-compensation', $target)->assertForbidden();
        $this->postJson('/api/v1/common/compensations', $target + ['paid_by' => CompensationSetting::PAID_BY_NONE])->assertForbidden();
        $this->assertDatabaseCount('compensation_settings', 0);
    }

    // ── Stats and team lookups ───────────────────────────────────────

    #[Test]
    public function the_profile_stats_count_the_partners_own_records(): void
    {
        $this->makeBorrower($this->partner);
        $this->makeBorrower($this->partner);
        $this->makeBorrower($this->otherPartner);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/stats')->assertOk()->assertJsonPath('data.id', $this->partner->id)->assertJsonPath('data.total_clients', 2);
    }

    #[Test]
    public function the_team_lookup_lists_only_the_callers_team(): void
    {
        $colleague = $this->makeStaffUser($this->partner);
        $outsider = $this->makeStaffUser($this->otherPartner);
        $hostStaff = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->admin);

        $ids = collect($this->postJson('/api/v1/common/get-all-users')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->admin->id, $colleague->id], $ids);

        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $ids = collect($this->postJson('/api/v1/common/get-all-users')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($hostStaff->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    #[Test]
    public function the_seat_count_is_reported_to_non_host_partners_only(): void
    {
        $this->makeStaffUser($this->partner);
        $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->admin);

        $this->getJson('/api/v1/common/user-count')->assertOk()->assertJsonPath('data.count', 2);

        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->getJson('/api/v1/common/user-count')->assertOk()->assertJsonPath('data.count', null);
    }

    #[Test]
    public function the_theme_reports_setup_flags_when_signed_in(): void
    {
        $this->getJson('/api/v1/common/get-theme')->assertOk()->assertJsonMissingPath('data.status_flags');

        $this->actingAsUser($this->admin);
        $this->getJson('/api/v1/common/partner-theme')->assertOk()->assertJsonStructure(['data' => ['branding', 'status_flags']]);
    }
}
