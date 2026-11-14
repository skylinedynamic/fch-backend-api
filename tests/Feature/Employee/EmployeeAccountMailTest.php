<?php

namespace Tests\Feature\Employee;

use App\Mail\AccountCreateNotificationMail;
use App\Mail\Payment\PaymentSuccessUserMail;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-196: Add Employee with "Create account" sent the welcome / login email
 * twice - once from EmployeeService::create() and again from the controller.
 * A paid seat's user also got a welcome with a password at creation, while
 * still inactive; activation replaces that password, so it never worked.
 *
 * BUG-197: the paid seat's activation email (welcome + login details) had the
 * subject "Payment Receipt - ...".
 */
class EmployeeAccountMailTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        // Plan & Fee > User Seat for Certified partners: $49 a seat, 3 free.
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => true,
            'total_amount' => 49, 'total_seat_count' => 3,
        ]);
        $this->role = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'sales']);
    }

    private function addEmployee(string $email, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/common/employees', array_merge([
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'email' => $email,
            'country' => 'United States',
            'create_account' => 1,
            'role_id' => $this->role->id,
        ], $extra))->assertCreated();
    }

    /** Mails of a class sent or queued to one address. */
    private function mailsTo(string $class, string $email): \Illuminate\Support\Collection
    {
        return Mail::sent($class)->merge(Mail::queued($class))->filter(fn ($mail) => $mail->hasTo($email))->values();
    }

    #[Test]
    public function a_free_seat_employee_gets_one_welcome_email(): void
    {
        $this->addEmployee('free.seat@example.test');

        $this->assertCount(1, $this->mailsTo(AccountCreateNotificationMail::class, 'free.seat@example.test'));
    }

    #[Test]
    public function a_paid_seat_employee_gets_no_welcome_email_before_the_seat_is_paid(): void
    {
        $this->addEmployee('paid.seat@example.test', ['is_paid' => 1, 'months' => 1]);

        $user = User::where('email', 'paid.seat@example.test')->firstOrFail();
        $this->assertSame(User::STATUS['inactive'], $user->status);
        $this->assertCount(0, $this->mailsTo(AccountCreateNotificationMail::class, 'paid.seat@example.test'));
    }

    #[Test]
    public function the_paid_seat_activation_email_says_the_account_is_active(): void
    {
        $this->addEmployee('paid.seat@example.test', ['is_paid' => 1, 'months' => 1]);
        $user = User::where('email', 'paid.seat@example.test')->firstOrFail();
        $invoice = Invoice::where('type', Invoice::INVOICE_TYPE_USER_SEAT)
            ->whereHas('subscription', fn ($q) => $q->where('entity_type', Employee::class)->where('entity_id', $user->entity_id))
            ->firstOrFail();

        $invoice->update(['status' => Invoice::STATUS_PAID, 'payment_date' => today()]);
        app(SubscriptionService::class)->handleSuccessfulPayment($invoice->fresh(), Subscription::find($invoice->subscription_id));

        $this->assertSame(User::STATUS['active'], $user->fresh()->status);
        $mails = $this->mailsTo(PaymentSuccessUserMail::class, 'paid.seat@example.test');
        $this->assertCount(1, $mails, 'one activation email with the login details');
        $subject = $mails->first()->envelope()->subject;
        $this->assertStringStartsWith('Your account is active - ', $subject);
        $this->assertStringNotContainsString('Payment Receipt', $subject);
    }
}
