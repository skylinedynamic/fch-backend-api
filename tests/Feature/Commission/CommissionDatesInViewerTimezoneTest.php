<?php

namespace Tests\Feature\Commission;

use App\Http\Resources\FCH\Common\Commission\CommissionDetailsResource;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionPayoutService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-120. Defect: a commission's dates - the list's Date, Funded Date and Paid
 * On - were the UTC day. A deal funded and paid on 21 Oct at 03:30 in Dhaka
 * (20 Oct 21:30 UTC) showed Oct 20, while its invoice and activity said 21 Oct.
 *
 * - The list's Date and a payout's Paid On are times (created_at, paid_at),
 *   stored in UTC and shown as the viewer's day (X-Timezone).
 * - funded_date and payout_date are dates: written as the day in the zone of
 *   whoever closed or paid the deal, and shown as they are.
 */
#[CoversClass(CommissionResource::class)]
#[CoversClass(CommissionDetailsResource::class)]
#[CoversClass(CommissionPayoutService::class)]
class CommissionDatesInViewerTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** 21 Oct 2026, 03:30 in Dhaka (UTC+6). */
    private const UTC_MOMENT = '2026-10-20 21:30:00';

    private Partner $host;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host LLC',
            'full_name' => 'Host LLC Owner',
            'email' => 'host@example.test',
        ]);
        $this->admin = User::create([
            'name' => 'Hal Host',
            'email' => 'admin@host.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A completed deal of another partner, funded at UTC_MOMENT. */
    private function commission(): Commission
    {
        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Reseller Co',
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Casey',
            'last_name' => 'Client',
            'email' => 'casey@example.test',
        ]);
        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'total_funded_amount' => 50000,
            'funded_date' => '2026-10-21',
        ]);

        $commission = Commission::create([
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
        DB::table('commissions')->where('id', $commission->id)->update(['created_at' => self::UTC_MOMENT]);

        return $commission->refresh();
    }

    private function details(Commission $commission, ?string $zone = 'Asia/Dhaka'): array
    {
        Passport::actingAs($this->admin);
        $request = $zone ? $this->withHeader('X-Timezone', $zone) : $this;

        return $request->getJson("/api/v1/common/commissions/{$commission->id}")->assertOk()->json('data');
    }

    /** Pays the partner at UTC_MOMENT. */
    private function payAtUtcMoment(Commission $commission): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_MOMENT, 'UTC'));
        Passport::actingAs($this->admin);
        $this->withHeader('X-Timezone', 'Asia/Dhaka')
            ->postJson("/api/v1/admin/commission/{$commission->id}/payout", ['partner_payment_method' => 'Cash', 'note' => 'Paid in person', 'environment' => PaymentProvider::MODE_PRODUCTION])
            ->assertOk();
        Carbon::setTestNow();
    }

    #[Test]
    public function the_list_date_is_the_viewers_day(): void
    {
        $this->commission();
        Passport::actingAs($this->admin);

        $rows = $this->withHeader('X-Timezone', 'Asia/Dhaka')
            ->postJson('/api/v1/common/commissions', ['page' => 1])
            ->assertOk()
            ->json('data');

        $this->assertSame('2026/10/21', $rows[0]['date']);
    }

    #[Test]
    public function the_funded_date_is_shown_as_it_was_saved_in_any_zone(): void
    {
        $commission = $this->commission();

        $this->assertSame('Oct 21, 2026', $this->details($commission)['funded_date']);
        $this->assertSame('Oct 21, 2026', $this->details($commission, 'America/Los_Angeles')['funded_date']);
    }

    #[Test]
    public function paid_on_is_the_viewers_day(): void
    {
        $commission = $this->commission();
        $this->payAtUtcMoment($commission);

        $data = $this->details($commission);

        $this->assertSame('Oct 21, 2026', $data['payout']['paid_at']);
        $this->assertSame('Oct 21, 2026', $data['payout_date']);
    }

    #[Test]
    public function paying_stores_the_payout_date_as_the_payers_day(): void
    {
        $commission = $this->commission();
        $this->payAtUtcMoment($commission);

        $this->assertStringStartsWith('2026-10-21', (string) DB::table('commissions')->where('id', $commission->id)->value('payout_date'));
    }

    #[Test]
    public function a_commission_paid_before_payouts_were_recorded_shows_its_payout_date_as_saved(): void
    {
        $commission = $this->commission();
        $commission->update(['payment_status' => Commission::PAYMENT_STATUS_PAID, 'payout_date' => '2026-10-21']);

        $this->assertSame('Oct 21, 2026', $this->details($commission)['payout']['paid_at']);
    }

    #[Test]
    public function without_a_timezone_header_a_time_is_the_utc_day(): void
    {
        $this->commission();
        Passport::actingAs($this->admin);

        $rows = $this->postJson('/api/v1/common/commissions', ['page' => 1])->assertOk()->json('data');

        $this->assertSame('2026/10/20', $rows[0]['date']);
    }
}
