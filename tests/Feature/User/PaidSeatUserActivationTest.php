<?php

namespace Tests\Feature\User;

use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\User\UserService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-123: a Certified partner at its seat limit bought a seat ($49, 1 month),
 * added the user and paid with Cash - the payment still Processing - and the
 * user was already Active. The email typed in the form was also replaced by the
 * employee record's.
 *
 * A paid seat's user is created inactive with the email as typed, stays
 * inactive while the seat invoice is Processing, and becomes active - with a
 * new password - only when the invoice is paid.
 */
#[CoversClass(UserService::class)]
#[CoversClass(SubscriptionService::class)]
class PaidSeatUserActivationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $owner;
    private Role $sales;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->owner = $this->makePartnerAdmin($this->partner);
        // Owner + 2 staff: the three free seats are taken.
        $this->makeStaffUser($this->partner);
        $this->makeStaffUser($this->partner);
        // Plan & Fee > User Seat for Certified partners: $49 a seat, 3 free.
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => true,
            'total_amount' => 49, 'total_seat_count' => 3,
        ]);
        $this->sales = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'sales']);
        $this->actingAsUser($this->owner);
    }

    /** Add the 4th user as a bought seat; returns the new user and its seat invoice. */
    private function buySeat(): array
    {
        $employee = Employee::create([
            'partner_id' => $this->partner->id,
            'first_name' => 'Cert',
            'last_name' => 'Staff Three',
            'email' => 'employee-record@example.test',
        ]);

        $response = $this->postJson('/api/v1/common/users', [
            'name' => 'QA-TEST Cert Staff Three',
            'email' => 'typed-in-form@example.test',
            'password' => 'secret123',
            'role_id' => $this->sales->id,
            'employee_id' => $employee->id,
            'is_paid' => true,
            'months' => 1,
        ])->assertSuccessful();

        $user = User::where('entity_type', Employee::class)->where('entity_id', $employee->id)->firstOrFail();
        $invoice = Invoice::findOrFail($response->json('data.invoice_id'));

        return [$user, $invoice];
    }

    private function settle(Invoice $invoice, string $status): void
    {
        $invoice->update(['status' => $status, 'payment_date' => $status === Invoice::STATUS_PAID ? today() : null]);
        app(SubscriptionService::class)->handleSuccessfulPayment($invoice->fresh(), Subscription::find($invoice->subscription_id));
    }

    #[Test]
    public function a_bought_seats_user_starts_inactive_with_the_email_typed_in_the_form(): void
    {
        [$user, $invoice] = $this->buySeat();

        $this->assertSame(User::STATUS['inactive'], $user->status);
        $this->assertSame('typed-in-form@example.test', $user->email, 'not the employee record\'s email');
        $this->assertSame(Invoice::INVOICE_TYPE_USER_SEAT, $invoice->type);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
    }

    #[Test]
    public function the_user_stays_inactive_while_the_cash_payment_is_processing(): void
    {
        [$user, $invoice] = $this->buySeat();

        $this->settle($invoice, Invoice::STATUS_PROCESSING);

        $this->assertSame(User::STATUS['inactive'], $user->fresh()->status);
    }

    #[Test]
    public function the_user_becomes_active_once_the_seat_is_paid(): void
    {
        [$user, $invoice] = $this->buySeat();

        $this->settle($invoice, Invoice::STATUS_PAID);

        $this->assertSame(User::STATUS['active'], $user->fresh()->status);
        $this->assertNotNull(Subscription::find($invoice->subscription_id)->fresh()->subscription_end_date);
    }

    // ── Renewal ──────────────────────────────────────────────────────

    /**
     * An active seat whose paid-through date is $endsOn, with its renewal
     * invoice for $months months raised and paid.
     */
    private function renew(\Carbon\Carbon $endsOn, int $months = 1): Subscription
    {
        [, $first] = $this->buySeat();
        $this->settle($first, Invoice::STATUS_PAID);

        $subscription = Subscription::find($first->subscription_id);
        $subscription->update(['subscription_end_date' => $endsOn]);

        $renewal = Invoice::create([
            'invoice_id' => 'INV-RENEW-' . uniqid(),
            'partner_id' => $this->partner->id,
            'subscription_id' => $subscription->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'payment_amount' => 49 * $months,
            'month_count' => $months,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_USER_SEAT,
            'is_partner_paying' => true,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $renewal->id]);

        $this->settle($renewal, Invoice::STATUS_PAID);

        return $subscription->fresh();
    }

    /**
     * Defect: a renewal paid late was added to the old paid-through date, so a
     * seat that lapsed in March and was paid in November ran to April - still
     * in the past. The user stayed locked out after paying, and the cron billed
     * the next month at once. Paid late, a seat runs from the day it is paid,
     * like every other subscription (SubscriptionService::nextCycleFrom()).
     */
    #[Test]
    public function a_renewal_paid_late_runs_from_the_day_it_is_paid(): void
    {
        $subscription = $this->renew(today()->subMonths(8));

        $this->assertSame(today()->addMonth()->toDateString(), $subscription->subscription_end_date->toDateString());
    }

    #[Test]
    public function a_renewal_paid_early_extends_the_current_paid_through_date(): void
    {
        $endsOn = today()->addDays(10);

        $subscription = $this->renew($endsOn->copy());

        $this->assertSame($endsOn->copy()->addMonth()->toDateString(), $subscription->subscription_end_date->toDateString());
    }

    #[Test]
    public function a_late_renewal_for_several_months_runs_that_many_months_from_today(): void
    {
        $subscription = $this->renew(today()->subMonths(3), months: 3);

        $this->assertSame(today()->addMonths(3)->toDateString(), $subscription->subscription_end_date->toDateString());
    }
}
