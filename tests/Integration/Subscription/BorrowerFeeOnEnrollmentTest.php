<?php

namespace Tests\Integration\Subscription;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use App\Services\FCH\Subscription\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower fee: every paid borrower of a partner other than the host costs
 * that partner a fixed fee, paid to the host.
 *
 * Defect: the fee was skipped whenever the host did the enrolling - converting
 * a white-label or certified partner's lead, or moving that partner's free-
 * trial borrower onto a paid plan. The partner then never owed the fee and its
 * borrower was never held back waiting for it. Whose borrower it is decides
 * the fee, not who pressed the button.
 */
#[CoversClass(EnrollmentService::class)]
class BorrowerFeeOnEnrollmentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->host = $this->makeHostPartner();

        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            'total_amount' => 800.00,
            'currency' => 'USD',
            'status' => true,
        ]);

        $this->plan = SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => Borrower::class,
            'total_amount' => 2497.00,
            'total_installment_count_upto' => 1,
            'currency' => 'USD',
            'status' => true,
        ]);
    }

    /** Enrol a new borrower of $partner, done by $actor. */
    private function enrol(Partner $partner, User $actor): Borrower
    {
        $borrower = $this->makeBorrower($partner, ['status' => Borrower::STATUS_ONBOARDING]);
        $this->actingAs($actor, 'api');

        app(EnrollmentService::class)->enrollBorrower(
            $borrower,
            $partner,
            $actor,
            ['plan_id' => $this->plan->id, 'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT],
            (bool) $actor->partner?->is_host
        );

        return $borrower->fresh();
    }

    private function feeFor(Borrower $borrower): ?Invoice
    {
        return Invoice::where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)->where('borrower_id', $borrower->id)->first();
    }

    private function assertFeeOwedToTheHostBy(Partner $partner, Borrower $borrower): void
    {
        $fee = $this->feeFor($borrower);

        $this->assertNotNull($fee, 'no borrower fee invoice was raised');
        $this->assertSame(Partner::class, $fee->entity_type);
        $this->assertEquals($partner->id, $fee->entity_id);
        $this->assertTrue((bool) $fee->is_partner_paying);
        $this->assertEquals(800.00, $fee->payment_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $fee->status);
        $this->assertFalse((bool) $borrower->partner_payment_for_me, 'the borrower is not held until the partner pays');
    }

    #[Test]
    public function the_host_enrolling_a_white_label_partners_borrower_raises_the_fee(): void
    {
        $partner = $this->makeWhiteLabelPartner();

        $borrower = $this->enrol($partner, $this->makePartnerAdmin($this->host));

        $this->assertFeeOwedToTheHostBy($partner, $borrower);
    }

    #[Test]
    public function the_host_enrolling_a_certified_partners_borrower_raises_the_fee(): void
    {
        $partner = $this->makeCertifiedPartner();

        $borrower = $this->enrol($partner, $this->makePartnerAdmin($this->host));

        $this->assertFeeOwedToTheHostBy($partner, $borrower);
    }

    #[Test]
    public function the_partner_enrolling_its_own_borrower_raises_the_fee(): void
    {
        $partner = $this->makeWhiteLabelPartner();

        $borrower = $this->enrol($partner, $this->makePartnerAdmin($partner));

        $this->assertFeeOwedToTheHostBy($partner, $borrower);
    }

    #[Test]
    public function the_hosts_own_borrower_has_no_fee(): void
    {
        $borrower = $this->enrol($this->host, $this->makePartnerAdmin($this->host));

        $this->assertNull($this->feeFor($borrower));
    }
}
