<?php

namespace Tests\Feature\CommissionPayout;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Compensation\CompensationSetting;
use App\Mail\Commission\CommissionPayoutMail;
use App\Models\Commission\CommissionPayout;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Payout section of a commission: for a client of the host partner, what
 * the user assigned to that client earns from the deal, per their Funding
 * compensation — a percentage of the admin commission, or a flat amount.
 */
#[CoversClass(CommissionPayoutService::class)]
class CommissionPayoutTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->host = $this->partner('Host LLC', true);
        $this->admin = $this->user('admin@host.test', $this->host);
    }

    private function partner(string $name, bool $isHost): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => $name,
            'full_name' => "$name Owner",
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
        ]);
    }

    private function user(string $email, Partner $partner, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Sam Seller',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
        ], $attributes));
    }

    /** A commission on a client of $partner, assigned to $assignee, with a $2,000 admin commission. */
    private function commission(Partner $partner, ?User $assignee, string $fundingStatus = FundingApplication::CLOSED_COMPLETED): Commission
    {
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Casey',
            'last_name' => 'Client',
            'email' => 'casey' . uniqid() . '@example.test',
            'assign_id' => $assignee?->id,
        ]);

        $application = FundingApplication::forceCreate(['borrower_id' => $borrower->id, 'status' => $fundingStatus]);

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

    private function compensate($entity, string $paidBy, array $rows): void
    {
        foreach ($rows as [$service, $payoutType, $amount]) {
            CompensationSetting::create([
                'partner_id' => $this->host->id,
                'entity_type' => $entity::class,
                'entity_id' => $entity->id,
                'paid_by' => $paidBy,
                'service' => $service,
                'payout_type' => $payoutType,
                'amount' => $amount,
            ]);
        }
    }

    private function payout(Commission $commission): ?array
    {
        Passport::actingAs($this->admin);

        return $this->getJson("/api/v1/common/commissions/{$commission->id}")
            ->assertOk()
            ->json('data.payout');
    }

    #[Test]
    public function a_funding_commission_rate_is_taken_from_the_admin_commission(): void
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_PER_DEAL, [
            ['Funding', CompensationSetting::PAYOUT_TYPE_COMMISSION, 10],
            ['Credit Card Stacking', CompensationSetting::PAYOUT_TYPE_FLAT_AMOUNT, 99],
        ]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertSame('user', $payout['payee']['type']);
        $this->assertSame($seller->id, $payout['payee']['id']);
        $this->assertSame('Sam Seller', $payout['payee']['name']);
        $this->assertSame('Funding', $payout['service']);
        $this->assertSame('Admin Commission', $payout['base_label']);
        $this->assertSame(CompensationSetting::PAYOUT_TYPE_COMMISSION, $payout['payout_type']);
        $this->assertEquals(10, $payout['rate']);
        $this->assertEquals(2000, $payout['base_amount']);
        $this->assertEquals(200, $payout['amount']); // 10% of $2,000
    }

    #[Test]
    public function a_flat_amount_is_paid_as_it_is(): void
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_PER_DEAL, [
            ['Funding', CompensationSetting::PAYOUT_TYPE_FLAT_AMOUNT, 350],
        ]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertSame(CompensationSetting::PAYOUT_TYPE_FLAT_AMOUNT, $payout['payout_type']);
        $this->assertEquals(350, $payout['amount']);
    }

    #[Test]
    public function a_rate_set_for_all_services_covers_funding(): void
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_PER_DEAL, [
            [CompensationSetting::SERVICE_ALL, CompensationSetting::PAYOUT_TYPE_COMMISSION, 25],
        ]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertSame(CompensationSetting::SERVICE_ALL, $payout['service']);
        $this->assertEquals(500, $payout['amount']); // 25% of $2,000
    }

    #[Test]
    public function an_employee_login_uses_the_employees_compensation(): void
    {
        // Compensation set on the Employee is shared with its login.
        $employee = Employee::create(['partner_id' => $this->host->id, 'first_name' => 'Sam Seller', 'email' => 'seller@host.test']);
        $seller = $this->user('seller@host.test', $this->host, [
            'user_type' => User::EMPLOYEE, 'entity_type' => Employee::class, 'entity_id' => $employee->id,
        ]);
        $this->compensate($employee, CompensationSetting::PAID_BY_PER_DEAL, [
            ['Funding', CompensationSetting::PAYOUT_TYPE_COMMISSION, 5],
        ]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertEquals(100, $payout['amount']); // 5% of $2,000
    }

    #[Test]
    public function someone_on_salary_earns_nothing_per_deal(): void
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_SALARY, [[null, null, 5000]]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertSame(CompensationSetting::PAID_BY_SALARY, $payout['paid_by']);
        $this->assertNull($payout['amount']);
    }

    #[Test]
    public function a_per_deal_setup_without_funding_earns_nothing_on_a_funding_deal(): void
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_PER_DEAL, [
            ['Credit Card Stacking', CompensationSetting::PAYOUT_TYPE_COMMISSION, 10],
        ]);

        $payout = $this->payout($this->commission($this->host, $seller));

        $this->assertNull($payout['service']);
        $this->assertNull($payout['amount']);
    }

    #[Test]
    public function a_client_with_no_one_assigned_shows_an_empty_payout(): void
    {
        $payout = $this->payout($this->commission($this->host, null));

        $this->assertNull($payout['payee']);
        $this->assertNull($payout['amount']);
    }

    #[Test]
    public function a_client_of_another_partner_pays_that_partners_commission(): void
    {
        // Same payout, shown the same way — the payee is the partner and the
        // percentage is its share of the total commission.
        $other = $this->partner('Reseller Co', false);

        $payout = $this->payout($this->commission($other, null));

        $this->assertSame('partner', $payout['payee']['type']);
        $this->assertSame($other->id, $payout['payee']['id']);
        $this->assertSame('Reseller Co', $payout['payee']['name']);
        $this->assertSame('resellerco@example.test', $payout['payee']['email']);
        $this->assertSame(CompensationSetting::PAYOUT_TYPE_COMMISSION, $payout['payout_type']);
        $this->assertEquals(6, $payout['rate']);
        $this->assertSame('Total Commission', $payout['base_label']);
        $this->assertEquals(5000, $payout['base_amount']);
        $this->assertEquals(3000, $payout['amount']);
        $this->assertTrue($payout['can_pay']);
    }

    #[Test]
    public function a_partner_with_no_commission_share_has_nothing_to_pay(): void
    {
        $other = $this->partner('Reseller Co', false);
        $commission = $this->commission($other, null);
        $commission->update(['partner_commission' => 0, 'partner_commission_amount' => 0]);

        $payout = $this->payout($commission);

        $this->assertNull($payout['amount']);
        $this->assertFalse($payout['can_pay']);
    }

    // ── Make Payment ──────────────────────────────────────────────────────

    private function sellerOnTenPercent(): User
    {
        $seller = $this->user('seller@host.test', $this->host);
        $this->compensate($seller, CompensationSetting::PAID_BY_PER_DEAL, [
            ['Funding', CompensationSetting::PAYOUT_TYPE_COMMISSION, 10],
        ]);

        return $seller;
    }

    /** One endpoint pays either payee: the client's assigned user (host clients) or the partner. */
    private function pay(Commission $commission, array $data = [])
    {
        Passport::actingAs($this->admin);

        return $this->postJson("/api/v1/admin/commission/{$commission->id}/payout", array_merge(
            ['partner_payment_method' => 'Cash', 'note' => 'Paid in person'],
            $data
        ));
    }

    #[Test]
    public function a_payable_payout_says_so(): void
    {
        $payout = $this->payout($this->commission($this->host, $this->sellerOnTenPercent()));

        $this->assertTrue($payout['can_pay']);
        $this->assertFalse($payout['is_paid']);
    }

    #[Test]
    public function making_the_payment_marks_the_commission_paid_and_completed(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent());

        $this->pay($commission)->assertOk();

        $commission->refresh();
        $this->assertSame(Commission::PAYMENT_STATUS_PAID, $commission->payment_status);
        $this->assertSame(Commission::STATUS_COMPLETED, $commission->commission_status);
        $this->assertNotNull($commission->payout_date);

        $payout = $this->payout($commission);
        $this->assertTrue($payout['is_paid']);
        $this->assertFalse($payout['can_pay']);
        $this->assertNotNull($payout['paid_at']);
    }

    #[Test]
    public function a_paid_payout_keeps_the_amount_it_was_paid_at(): void
    {
        // Changing the rate later, or the client's assigned user, doesn't
        // rewrite what was already paid.
        $seller = $this->sellerOnTenPercent();
        $commission = $this->commission($this->host, $seller);
        $this->pay($commission)->assertOk();

        CompensationSetting::query()->update(['amount' => 50]);
        $commission->borrower->update(['assign_id' => null]);

        $payout = $this->payout($commission);
        $this->assertEquals(200, $payout['amount']);
        $this->assertSame($seller->id, $payout['payee']['id']);
        $this->assertSame('Sam Seller', $payout['payee']['name']);
    }

    #[Test]
    public function a_payout_cannot_be_paid_twice(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent());
        $this->pay($commission)->assertOk();

        $this->pay($commission)->assertStatus(422);
    }

    #[Test]
    public function a_payout_waits_for_the_funding_to_close(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent(), 'Processing');

        $this->assertFalse($this->payout($commission)['can_pay']);
        $this->pay($commission)->assertStatus(422);
        $this->assertNotSame(Commission::PAYMENT_STATUS_PAID, $commission->refresh()->payment_status);
    }

    #[Test]
    public function nothing_to_pay_cannot_be_paid(): void
    {
        $commission = $this->commission($this->host, null);

        $this->assertFalse($this->payout($commission)['can_pay']);
        $this->pay($commission)->assertStatus(422);
    }

    #[Test]
    public function paying_a_partner_marks_the_commission_paid_and_completed(): void
    {
        $other = $this->partner('Reseller Co', false);
        $commission = $this->commission($other, null);

        $this->pay($commission)->assertOk();

        $commission->refresh();
        $this->assertSame(Commission::PAYMENT_STATUS_PAID, $commission->payment_status);
        $this->assertSame(Commission::STATUS_COMPLETED, $commission->commission_status);
        $this->assertTrue($this->payout($commission)['is_paid']);
    }

    #[Test]
    public function a_payment_method_is_needed_to_pay(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent());

        $this->pay($commission, ['partner_payment_method' => ''])->assertStatus(422);

        $this->assertSame(0, CommissionPayout::count());
    }

    // ── Payout, invoice and payment records — the same for either payee ───

    #[Test]
    public function paying_an_assigned_user_records_a_payout_an_invoice_and_a_payment(): void
    {
        $seller = $this->sellerOnTenPercent();
        $commission = $this->commission($this->host, $seller);

        $this->pay($commission, ['partner_payment_method' => 'Cheque'])->assertOk();

        $invoice = Invoice::where('type', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(200, $invoice->payment_amount);
        $this->assertSame(User::class, $invoice->entity_type);
        $this->assertEquals($seller->id, $invoice->entity_id);
        $this->assertSame('Sam Seller', $invoice->customer_name);
        $this->assertSame('seller@host.test', $invoice->email);
        $this->assertEquals($this->host->id, $invoice->partner_id);
        $this->assertEquals($commission->borrower_id, $invoice->borrower_id);
        $this->assertSame('Paid in person', $invoice->note);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertEquals(200, $payment->amount);
        $this->assertSame('Cheque', $payment->payment_type);
        $this->assertSame(User::class, $payment->entity_type);
        $this->assertEquals($seller->id, $payment->entity_id);

        $payout = CommissionPayout::where('commission_id', $commission->id)->sole();
        $this->assertSame(User::class, $payout->entity_type);
        $this->assertSame($seller->id, $payout->entity_id);
        $this->assertSame('Sam Seller', $payout->name);
        $this->assertSame('seller@host.test', $payout->email);
        $this->assertSame($invoice->id, $payout->invoice_id);
        $this->assertSame($payment->id, $payout->payment_id);
        $commission->refresh();
        $this->assertSame($commission->currency, $payout->currency);
        $this->assertSame($commission->currency_symbol, $payout->currency_symbol);
        $this->assertEquals($commission->conversion_rate, $payout->conversion_rate);
        $this->assertNotNull($payout->paid_at);
    }

    #[Test]
    public function paying_a_partner_records_a_payout_an_invoice_and_a_payment(): void
    {
        $other = $this->partner('Reseller Co', false);
        $commission = $this->commission($other, null);

        $this->pay($commission, ['partner_payment_method' => 'Cash'])->assertOk();

        $invoice = Invoice::where('type', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(3000, $invoice->payment_amount);
        $this->assertSame(Partner::class, $invoice->entity_type);
        $this->assertEquals($other->id, $invoice->entity_id);
        $this->assertEquals($other->id, $invoice->partner_id);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertEquals(3000, $payment->amount);
        $this->assertSame('Cash', $payment->payment_type);

        $payout = CommissionPayout::where('commission_id', $commission->id)->sole();
        $this->assertSame(Partner::class, $payout->entity_type);
        $this->assertSame($other->id, $payout->entity_id);
        $this->assertSame('Reseller Co', $payout->name);
        $this->assertSame($invoice->id, $payout->invoice_id);
        $this->assertSame($payment->id, $payout->payment_id);
    }

    #[Test]
    public function a_paid_payout_reads_its_amount_and_method_from_the_invoice_and_payment(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent());
        $this->pay($commission, ['partner_payment_method' => 'Cheque'])->assertOk();

        $payout = $this->payout($commission);

        $this->assertEquals(200, $payout['amount']);
        $this->assertSame('Cheque', $payout['payment_method']);
        $this->assertSame(CompensationSetting::PAYOUT_TYPE_COMMISSION, $payout['payout_type']);
    }

    #[Test]
    public function a_partner_payout_made_before_payouts_were_recorded_still_shows_as_paid(): void
    {
        // Commissions paid by the older flow have an invoice and a payment but
        // no payout record. They must read as paid, not as waiting to be paid.
        $other = $this->partner('Reseller Co', false);
        $commission = $this->commission($other, null);
        $commission->update([
            'payment_status' => Commission::PAYMENT_STATUS_PAID,
            'commission_status' => Commission::STATUS_COMPLETED,
            'payout_date' => '2026-07-27',
        ]);
        $invoice = Invoice::forceCreate([
            'invoice_id' => 'INV-OLD-1',
            'partner_id' => $other->id,
            'borrower_id' => $commission->borrower_id,
            'entity_type' => Partner::class,
            'entity_id' => $other->id,
            'payment_amount' => 3000,
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
        ]);
        Payment::forceCreate([
            'partner_id' => $other->id,
            'borrower_id' => $commission->borrower_id,
            'invoice_id' => $invoice->id,
            'amount' => 3000,
            'payment_from' => 'Cheque',
            'payment_type' => 'Cheque',
            'status' => Payment::STATUS_SUCCEEDED,
        ]);

        $payout = $this->payout($commission);

        $this->assertTrue($payout['is_paid']);
        $this->assertFalse($payout['can_pay']);
        $this->assertSame('partner', $payout['payee']['type']);
        $this->assertEquals(3000, $payout['amount']);
        $this->assertSame('Cheque', $payout['payment_method']);
        $this->assertSame('Jul 27, 2026', $payout['paid_at']);
    }

    #[Test]
    public function whoever_is_paid_is_emailed_about_it(): void
    {
        $other = $this->partner('Reseller Co', false);
        $this->pay($this->commission($other, null))->assertOk();
        $this->pay($this->commission($this->host, $this->sellerOnTenPercent()))->assertOk();

        Mail::assertSent(CommissionPayoutMail::class, 2);
        Mail::assertSent(CommissionPayoutMail::class, fn ($mail) => $mail->hasTo($other->email));
        Mail::assertSent(CommissionPayoutMail::class, fn ($mail) => $mail->hasTo('seller@host.test'));
    }

    #[Test]
    public function the_payout_email_is_addressed_to_the_payee_with_the_amount_they_were_paid(): void
    {
        // The assigned user's email must name them and show their payout, not
        // the host partner's name and share of the commission.
        $this->pay($this->commission($this->host, $this->sellerOnTenPercent()), ['note' => 'Thanks for the deal'])->assertOk();

        Mail::assertSent(CommissionPayoutMail::class, function ($mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Sam Seller', $html);
            $this->assertStringContainsString('200.00', $html);
            $this->assertStringNotContainsString('3,000.00', $html);
            $this->assertStringContainsString('Thanks for the deal', $html);

            return true;
        });
    }

    #[Test]
    public function the_partner_payout_email_shows_the_partners_name_and_amount(): void
    {
        $other = $this->partner('Reseller Co', false);
        $this->pay($this->commission($other, null))->assertOk();

        Mail::assertSent(CommissionPayoutMail::class, function ($mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Reseller Co Owner', $html);
            $this->assertStringContainsString('3,000.00', $html);

            return true;
        });
    }

    #[Test]
    public function a_payment_that_cannot_be_made_leaves_no_records_behind(): void
    {
        $commission = $this->commission($this->host, $this->sellerOnTenPercent(), 'Processing');

        $this->pay($commission)->assertStatus(422);

        $this->assertSame(0, CommissionPayout::count());
        $this->assertSame(0, Invoice::where('type', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT)->count());
        $this->assertSame(0, Payment::count());
    }
}
