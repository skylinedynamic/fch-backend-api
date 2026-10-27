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
}
