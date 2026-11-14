<?php

namespace Tests\Feature\Commission;

use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-011: "locked after close" lived in the portal only. Through the API,
 * a Closed (Completed) application could be closed again with another funded
 * amount - the commission recomputed and set back to Processing/Unpaid, even
 * one already paid out - and save-commission set any commission back to
 * Draft/Pending. Once closed, the funded amount is fixed; once past Draft, the
 * commission is fixed; a paid commission is never overwritten.
 */
class ClosedApplicationCommissionLockTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    private User $hostAdmin;

    private User $hostStaff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->hostStaff = $this->makeStaffUser($host);
        $this->partner = $this->makeWhiteLabelPartner();
    }

    /** An application with a lender, in the given status and funded amount. */
    private function application(string $status, ?float $funded = null): FundingApplication
    {
        $lenderId = DB::table('lenders')->insertGetId(['name' => 'Credibly', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $borrower = $this->makeBorrower($this->partner);

        return FundingApplication::forceCreate([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'status' => $status,
            'requested_amount' => 15000,
            'total_funded_amount' => $funded,
            'lender_ids' => [$lenderId],
            'assign_lender' => $lenderId,
        ]);
    }

    /** 10% of 15,000, 70/30 between host and partner. */
    private function commission(FundingApplication $application, string $status, string $payment): Commission
    {
        return Commission::forceCreate([
            'funding_application_id' => $application->id,
            'borrower_id' => $application->borrower_id,
            'partner_id' => $this->partner->id,
            'total_commission' => 10,
            'admin_commission' => 70,
            'partner_commission' => 30,
            'total_commission_amount' => 1500,
            'admin_commission_amount' => 1050,
            'partner_commission_amount' => 450,
            'commission_status' => $status,
            'payment_status' => $payment,
        ]);
    }

    private function close(FundingApplication $application, float $funded)
    {
        $this->actingAsUser($this->hostAdmin);

        return $this->postJson('/api/v1/common/request-application/funding-application/status-update', [
            'funding_application_id' => $application->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'funded_amount' => $funded,
        ]);
    }

    private function saveCommission(FundingApplication $application, array $rates = ['total_commission' => 50, 'admin_commission' => 100])
    {
        $this->actingAsUser($this->hostStaff);

        return $this->postJson('/api/v1/admin/funding-application/save-commission', ['funding_application_id' => $application->id] + $rates);
    }

    private function assertCommissionUnchanged(Commission $commission, string $status, string $payment): void
    {
        $commission->refresh();
        $this->assertSame($status, $commission->commission_status);
        $this->assertSame($payment, $commission->payment_status);
        $this->assertEquals(10, $commission->total_commission);
        $this->assertEquals(1500, $commission->total_commission_amount);
        $this->assertEquals(1050, $commission->admin_commission_amount);
        $this->assertEquals(450, $commission->partner_commission_amount);
    }

    /** @return iterable<string, array{string, string}> */
    public static function settledCommissions(): iterable
    {
        yield 'processing, unpaid' => [Commission::STATUS_PROCESSING, Commission::PAYMENT_STATUS_UNPAID];
        yield 'paid out' => [Commission::STATUS_COMPLETED, Commission::PAYMENT_STATUS_PAID];
    }

    /** QA scenario A: app 106 closed again with $30,000. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('settledCommissions')]
    public function a_closed_application_is_not_closed_again_with_another_funded_amount(string $status, string $payment): void
    {
        $application = $this->application(FundingApplication::CLOSED_COMPLETED, 15000);
        $commission = $this->commission($application, $status, $payment);

        $this->close($application, 30000)
            ->assertStatus(422)
            ->assertJsonPath('message', __('application.funded_amount_locked'));

        $this->assertEquals(15000, $application->fresh()->total_funded_amount);
        $this->assertCommissionUnchanged($commission, $status, $payment);
    }

    /** The same close sent twice (a double-click) is still a quiet repeat. */
    #[Test]
    public function the_same_close_repeated_changes_nothing(): void
    {
        $application = $this->application(FundingApplication::CLOSED_COMPLETED, 15000);
        $commission = $this->commission($application, Commission::STATUS_COMPLETED, Commission::PAYMENT_STATUS_PAID);

        $this->close($application, 15000)->assertOk();

        $this->assertCommissionUnchanged($commission, Commission::STATUS_COMPLETED, Commission::PAYMENT_STATUS_PAID);
    }

    /** QA scenario B: save-commission on app 88's Processing commission, and on one paid out. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('settledCommissions')]
    public function a_commission_past_draft_is_not_saved_again(string $status, string $payment): void
    {
        $application = $this->application(FundingApplication::CLOSED_COMPLETED, 15000);
        $commission = $this->commission($application, $status, $payment);

        $this->saveCommission($application)
            ->assertStatus(422)
            ->assertJsonPath('message', __('application.commission_locked'));

        $this->assertCommissionUnchanged($commission, $status, $payment);
    }

    /** A Draft commission is still set and reset, before or after the close. */
    #[Test]
    public function a_draft_commission_is_still_saved(): void
    {
        $application = $this->application(FundingApplication::CLOSED_COMPLETED, 15000);
        $commission = $this->commission($application, Commission::STATUS_DRAFT, Commission::PAYMENT_STATUS_PENDING);

        $this->saveCommission($application, ['total_commission' => 5, 'admin_commission' => 100])->assertOk();

        $this->assertEquals(5, $commission->fresh()->total_commission);
        $this->assertEquals(750, $commission->fresh()->total_commission_amount);
    }

    /** The first close still turns the Draft commission into Processing/Unpaid on the funded amount. */
    #[Test]
    public function the_first_close_still_starts_the_commission(): void
    {
        $application = $this->application(FundingApplication::APPROVED_FINAL);
        $commission = $this->commission($application, Commission::STATUS_DRAFT, Commission::PAYMENT_STATUS_PENDING);

        $this->close($application, 20000)->assertOk();

        $commission->refresh();
        $this->assertSame(Commission::STATUS_PROCESSING, $commission->commission_status);
        $this->assertSame(Commission::PAYMENT_STATUS_UNPAID, $commission->payment_status);
        $this->assertEquals(2000, $commission->total_commission_amount);
        $this->assertEquals(1400, $commission->admin_commission_amount);
        $this->assertEquals(600, $commission->partner_commission_amount);
    }

    /** However an application comes to be closed again, a paid commission is never reset or recomputed. */
    #[Test]
    public function closing_never_overwrites_a_paid_commission(): void
    {
        $application = $this->application(FundingApplication::FUNDED, 15000);
        $commission = $this->commission($application, Commission::STATUS_COMPLETED, Commission::PAYMENT_STATUS_PAID);

        $this->close($application, 30000)->assertOk();

        $this->assertCommissionUnchanged($commission, Commission::STATUS_COMPLETED, Commission::PAYMENT_STATUS_PAID);
    }
}
