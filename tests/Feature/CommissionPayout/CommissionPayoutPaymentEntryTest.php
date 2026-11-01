<?php

namespace Tests\Feature\CommissionPayout;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Compensation\CompensationSetting;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a commission payout reads in the Payments list: who it was paid to, and
 * whether it's a Credit (for whoever received it) or a Debit (for whoever paid).
 */
class CommissionPayoutPaymentEntryTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $reseller;
    private User $admin;
    private User $resellerUser;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->host = $this->partner('Host LLC', true);
        $this->reseller = $this->partner('Reseller Co', false);
        // The host's own login (its owner), who pays commissions out.
        $this->admin = $this->user('admin@host.test', $this->host, ['entity_type' => Partner::class, 'entity_id' => $this->host->id]);
        $this->resellerUser = $this->user('owner@reseller.test', $this->reseller, ['user_type' => User::PARTNER, 'name' => 'Reseller Owner']);
        $this->seller = $this->user('seller@host.test', $this->host);

        CompensationSetting::create([
            'partner_id' => $this->host->id,
            'entity_type' => User::class,
            'entity_id' => $this->seller->id,
            'paid_by' => CompensationSetting::PAID_BY_PER_DEAL,
            'service' => 'Funding',
            'payout_type' => CompensationSetting::PAYOUT_TYPE_COMMISSION,
            'amount' => 10,
        ]);
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

    private function commission(Partner $partner, ?User $assignee): Commission
    {
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Casey',
            'last_name' => 'Client',
            'email' => 'casey' . uniqid() . '@example.test',
            'assign_id' => $assignee?->id,
        ]);
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

    /** Pay a commission as the host admin, then return to the viewer under test. */
    private function pay(Commission $commission): void
    {
        Passport::actingAs($this->admin);
        $this->postJson("/api/v1/admin/commission/{$commission->id}/payout", ['partner_payment_method' => 'Cash', 'environment' => PaymentProvider::MODE_PRODUCTION])->assertOk();
    }

    /** The Payments list as $viewer sees it, optionally filtered by Credit/Debit. */
    private function listFor(User $viewer, ?string $type = null): array
    {
        Passport::actingAs($viewer);

        return $this->postJson('/api/v1/common/payments/fetch-payments', array_filter([
            'page' => 1,
            'per_page' => 50,
            'transaction_type' => $type,
        ]))->assertOk()->json('data');
    }

    private function rowFor(array $rows, string $amountFragment): ?array
    {
        foreach ($rows as $row) {
            if (str_contains((string) $row['amount'], $amountFragment)) {
                return $row;
            }
        }

        return null;
    }

    // ── Name ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_payment_to_an_assigned_user_shows_that_users_name(): void
    {
        $this->pay($this->commission($this->host, $this->seller));

        $row = $this->rowFor($this->listFor($this->admin), '200.00');

        $this->assertNotNull($row);
        $this->assertSame('Sam Seller', $row['name']);
        $this->assertSame('seller@host.test', $row['email']);
    }

    #[Test]
    public function a_payment_to_a_partner_shows_that_partners_name(): void
    {
        $this->pay($this->commission($this->reseller, null));

        $row = $this->rowFor($this->listFor($this->admin), '3,000.00');

        $this->assertNotNull($row);
        $this->assertSame('Reseller Co Owner', $row['partner_name']);
    }

    // ── Credit / Debit ────────────────────────────────────────────────────

    #[Test]
    public function the_admin_who_pays_sees_a_debit_for_either_payee(): void
    {
        $this->pay($this->commission($this->host, $this->seller));
        $this->pay($this->commission($this->reseller, null));

        $rows = $this->listFor($this->admin);

        $this->assertSame('Debit', $this->rowFor($rows, '200.00')['transaction_type']);
        $this->assertSame('Debit', $this->rowFor($rows, '3,000.00')['transaction_type']);
    }

    #[Test]
    public function the_partner_who_is_paid_sees_a_credit(): void
    {
        $this->pay($this->commission($this->reseller, null));

        $row = $this->rowFor($this->listFor($this->resellerUser), '3,000.00');

        $this->assertNotNull($row);
        $this->assertSame('Credit', $row['transaction_type']);
    }

    #[Test]
    public function the_assigned_user_who_is_paid_sees_a_credit(): void
    {
        $this->pay($this->commission($this->host, $this->seller));

        $row = $this->rowFor($this->listFor($this->seller), '200.00');

        $this->assertNotNull($row);
        $this->assertSame('Credit', $row['transaction_type']);
    }

    #[Test]
    public function filtering_by_type_agrees_with_what_each_row_shows(): void
    {
        $this->pay($this->commission($this->host, $this->seller));
        $this->pay($this->commission($this->reseller, null));

        // The admin paid both.
        $this->assertCount(2, $this->listFor($this->admin, 'Debit'));
        $this->assertCount(0, $this->listFor($this->admin, 'Credit'));

        // The partner received its own.
        $this->assertCount(1, $this->listFor($this->resellerUser, 'Credit'));
        $this->assertCount(0, $this->listFor($this->resellerUser, 'Debit'));
    }

    #[Test]
    public function other_payments_keep_their_credit_and_debit(): void
    {
        // A partner paying the host (a subscription fee, say): a Credit for the
        // host, a Debit for the partner paying it.
        Payment::forceCreate([
            'partner_id' => $this->reseller->id,
            'entity_type' => Partner::class,
            'entity_id' => (string) $this->reseller->id,
            'amount' => 99,
            'payment_from' => 'Stripe',
            'payment_type' => 'card',
            'status' => Payment::STATUS_SUCCEEDED,
            'is_partner_paying' => true,
        ]);

        $this->assertSame('Credit', $this->rowFor($this->listFor($this->admin), '99.00')['transaction_type']);
        $this->assertSame('Debit', $this->rowFor($this->listFor($this->resellerUser), '99.00')['transaction_type']);

        $this->assertCount(1, $this->listFor($this->admin, 'Credit'));
        $this->assertCount(1, $this->listFor($this->resellerUser, 'Debit'));
    }
}
