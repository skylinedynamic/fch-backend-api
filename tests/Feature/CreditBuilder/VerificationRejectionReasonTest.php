<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\CreditBuilder\VerificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-083: a reviewer rejecting a credit builder task wrote a reason
 * ("phone not listed in directories"), but every rejection was saved as
 * "No matching business record found." - the automated check's wording - and
 * the client's Overview never received a reason at all.
 */
#[CoversClass(VerificationCenterService::class)]
class VerificationRejectionReasonTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const REASON = 'phone not listed in directories';

    private Partner $partner;
    private Tier $tier;
    private TierTask $task;
    private User $client;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($host));

        $this->tier = Tier::create(['name' => 'Tier 1', 'title' => 'Starter', 'code' => 'T1', 'order' => 1]);
        $this->task = TierTask::create(['tier_id' => $this->tier->id, 'title' => 'Business Phone & Directory Listing', 'order' => 1]);
        $this->client = $this->makeBorrowerUser($this->partner, ['current_tier' => $this->tier->id]);
        $this->borrower = Borrower::findOrFail($this->client->entity_id);
    }

    private function request(): BorrowerTaskVerificationRequest
    {
        return BorrowerTaskVerificationRequest::forceCreate([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'task_id' => $this->task->id,
            'tier_id' => $this->tier->id,
            'status' => BorrowerTaskVerificationRequest::PENDING,
        ]);
    }

    private function reject(BorrowerTaskVerificationRequest $request): array
    {
        return app(VerificationCenterService::class)->updateStatus([
            'request_ids' => [$request->id],
            'status' => BorrowerTaskVerificationRequest::REJECTED,
            'note' => self::REASON,
        ]);
    }

    #[Test]
    public function a_manual_rejection_keeps_the_reviewers_reason(): void
    {
        $request = $this->request();

        $this->reject($request);

        $request->refresh();
        $this->assertSame(BorrowerTaskVerificationRequest::REJECTED, $request->status);
        $this->assertSame(self::REASON, $request->note);
    }

    #[Test]
    public function the_reviewers_summary_names_the_rejection_and_its_reason(): void
    {
        $results = $this->reject($this->request());

        $this->assertFalse($results[0]['is_verified']);
        $this->assertSame(__('verification_center.rejected_with_reason', ['reason' => self::REASON]), $results[0]['message']);
        $this->assertStringNotContainsString(__('verification_center.verification_failed'), $results[0]['message']);
    }

    #[Test]
    public function the_clients_overview_shows_the_reason_on_the_rejected_task(): void
    {
        $this->reject($this->request());
        $this->actingAsUser($this->client);

        $this->getJson('/api/v1/borrower/credit-builder/task-overview')
            ->assertOk()
            ->assertJsonPath('data.rejected.0.title', 'Business Phone & Directory Listing')
            ->assertJsonPath('data.rejected.0.rejection_reason', self::REASON);
    }
}
