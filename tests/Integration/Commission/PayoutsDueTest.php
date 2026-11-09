<?php

namespace Tests\Integration\Commission;

use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Compensation\CompensationSetting;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionPayoutService;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * CommissionViewer::payoutsDue() with the real payout and compensation services.
 *
 * Eloquent only enforces strict lazy loading on models hydrated together, so
 * the assignee's [entity] failure needs two different assignees - one is not
 * enough to reproduce it.
 */
#[CoversClass(CommissionViewer::class)]
class PayoutsDueTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
    }

    private function staffPaidPerDeal(float $rate): User
    {
        $staff = $this->makeStaffUser($this->host);

        CompensationSetting::create([
            'partner_id' => $this->host->id,
            'entity_type' => Employee::class,
            'entity_id' => $staff->entity_id,
            'paid_by' => CompensationSetting::PAID_BY_PER_DEAL,
            'service' => CommissionPayoutService::SERVICE_FUNDING,
            'payout_type' => CompensationSetting::PAYOUT_TYPE_COMMISSION,
            'amount' => $rate,
        ]);

        return $staff;
    }

    private function fundedDeal(User $assignee, float $adminCommission): void
    {
        $borrower = $this->makeBorrower($this->host, ['assign_id' => $assignee->id]);

        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->host->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
        ]);

        Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $this->host->id,
            'total_commission' => 10,
            'admin_commission' => 100,
            'partner_commission' => 0,
            'total_commission_amount' => $adminCommission,
            'admin_commission_amount' => $adminCommission,
            'partner_commission_amount' => 0,
            'commission_status' => Commission::STATUS_PROCESSING,
            'payment_status' => Commission::PAYMENT_STATUS_UNPAID,
        ]);
    }

    #[Test]
    public function it_totals_the_payouts_of_different_staff_members_from_their_employee_compensation(): void
    {
        $this->fundedDeal($this->staffPaidPerDeal(10), 1000);
        $this->fundedDeal($this->staffPaidPerDeal(20), 2000);

        $owner = $this->makePartnerAdmin($this->host);

        [$amount, $deals] = CommissionViewer::for($owner)->payoutsDue(Commission::query());

        $this->assertEquals(500, $amount); // 10% of 1,000 + 20% of 2,000
        $this->assertSame(2, $deals);
    }
}
