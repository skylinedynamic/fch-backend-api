<?php

namespace Tests\Integration\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Employee\EmployeeService;
use App\Services\FCH\Common\User\UserService;
use App\Services\FCH\Subscription\EnrollmentService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * invoices.customer_name is the PERSON billed, never their company.
 *
 * The borrower fee, the employee seat and the seat renewal used to save the
 * partner's company_name there, so those invoices' emails, PDFs and Stripe
 * descriptions named a company where every other invoice names a person.
 */
#[CoversClass(EnrollmentService::class)]
#[CoversClass(EmployeeService::class)]
#[CoversClass(UserService::class)]
#[CoversClass(SubscriptionService::class)]
class InvoiceCustomerNameTest extends TestCase
{
    use RefreshDatabase;

    private Partner $reseller;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'full_name' => 'Hal Host',
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
        ]);

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);

        // The plan a paid seat is sold under.
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'total_amount' => 49.00,
            'currency' => 'USD',
            'status' => true,
        ]);
    }

    private function resellerAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Rita Reseller',
            'email' => 'rita-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->reseller->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'status' => User::STATUS['active'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function employee(string $name = 'Eve Employee'): Employee
    {
        return Employee::create([
            'partner_id' => $this->reseller->id,
            'first_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '-' . uniqid() . '@example.test',
        ]);
    }

    #[Test]
    public function a_borrower_fee_is_saved_in_the_partners_name(): void
    {
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            'total_amount' => 800.00,
            'currency' => 'USD',
            'status' => true,
        ]);

        $plan = SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => Borrower::class,
            'total_amount' => 2497.00,
            'total_installment_count_upto' => 1,
            'currency' => 'USD',
            'status' => true,
        ]);

        $borrower = Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ONBOARDING,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
        ]);

        $this->actingAs($this->resellerAdmin(), 'api');

        app(EnrollmentService::class)->enrollBorrower(
            $borrower,
            $this->reseller,
            $this->resellerAdmin(),
            ['plan_id' => $plan->id, 'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT],
            false
        );

        $fee = Invoice::where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)->sole();

        $this->assertSame('Rita Reseller', $fee->customer_name);
    }

    #[Test]
    public function a_seat_bought_with_a_new_employee_is_saved_in_the_partners_name(): void
    {
        $this->actingAs($this->resellerAdmin(), 'api');

        app(EmployeeService::class)->create(
            ['first_name' => 'Eve Employee', 'email' => 'eve@example.test', 'is_paid' => true, 'months' => 1],
            ['name' => 'Eve Employee', 'email' => 'eve@example.test', 'plain_password' => 'secret-password']
        );

        $seat = Invoice::where('type', Invoice::INVOICE_TYPE_USER_SEAT)->sole();

        $this->assertSame('Rita Reseller', $seat->customer_name);
    }

    #[Test]
    public function a_seat_bought_with_a_new_user_is_saved_in_the_partners_name(): void
    {
        $this->actingAs($this->resellerAdmin(), 'api');

        app(UserService::class)->create([
            'name' => 'Uma User',
            'email' => 'uma@example.test',
            'password' => 'secret-password',
            'employee_id' => $this->employee('Uma User')->id,
            'is_paid' => true,
            'months' => 1,
        ]);

        $seat = Invoice::where('type', Invoice::INVOICE_TYPE_USER_SEAT)->sole();

        $this->assertSame('Rita Reseller', $seat->customer_name);
    }

    #[Test]
    public function a_partner_with_no_owner_name_never_falls_back_to_the_company(): void
    {
        $this->reseller->update(['full_name' => null]);
        $this->actingAs($this->resellerAdmin(), 'api');

        app(UserService::class)->create([
            'name' => 'Uma User',
            'email' => 'uma@example.test',
            'password' => 'secret-password',
            'employee_id' => $this->employee('Uma User')->id,
            'is_paid' => true,
            'months' => 1,
        ]);

        $seat = Invoice::where('type', Invoice::INVOICE_TYPE_USER_SEAT)->sole();

        $this->assertNotSame('Reseller LLC', $seat->customer_name);
    }

    #[Test]
    public function a_seat_renewal_is_saved_in_the_partners_name(): void
    {
        $employee = $this->employee();

        DB::table('users')->insert([
            'name' => 'Eve Employee',
            'email' => 'eve-login@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->reseller->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'status' => User::STATUS['active'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Subscription::create([
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 49.00,
            'per_seat_amount' => 49.00,
            'current_phase' => Subscription::PHASE_USER_SEAT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->subMonth(),
            'subscription_end_date' => today()->subDay(),
        ]);

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        $renewal = Invoice::where('type', Invoice::INVOICE_TYPE_USER_SEAT)->sole();

        $this->assertSame('Rita Reseller', $renewal->customer_name);
    }
}
