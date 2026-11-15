<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\CreditBuilder\OverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-188: every submission of a task is a new verification request, and the
 * old ones are kept. Task activity listed all of them, so a task rejected,
 * resubmitted and verified still showed under "Needs attention" - and a task
 * verified several times counted several times. Each task now appears once,
 * as its latest request says.
 */
#[CoversClass(OverviewService::class)]
class TaskOverviewLatestRequestTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Tier $tier;
    private User $client;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->tier = Tier::create(['name' => 'Tier 1', 'title' => 'Starter', 'code' => 'T1', 'order' => 1]);
        $this->client = $this->makeBorrowerUser($this->partner, ['current_tier' => $this->tier->id]);
        $this->borrower = Borrower::findOrFail($this->client->entity_id);
    }

    private function task(string $title): TierTask
    {
        return TierTask::create(['tier_id' => $this->tier->id, 'title' => $title, 'order' => 1]);
    }

    /** Submissions of $task in order, each ending in the given status. */
    private function submissions(TierTask $task, string ...$statuses): void
    {
        foreach ($statuses as $status) {
            BorrowerTaskVerificationRequest::forceCreate([
                'borrower_id' => $this->borrower->id,
                'partner_id' => $this->partner->id,
                'task_id' => $task->id,
                'tier_id' => $this->tier->id,
                'status' => $status,
            ]);
        }
    }

    private function overview(): array
    {
        $this->actingAsUser($this->client);

        return $this->getJson('/api/v1/borrower/credit-builder/task-overview')->assertOk()->json('data');
    }

    private function titles(array $items): array
    {
        return array_column($items, 'title');
    }

    #[Test]
    public function each_task_is_listed_once_as_its_latest_request_says(): void
    {
        $R = BorrowerTaskVerificationRequest::REJECTED;
        $V = BorrowerTaskVerificationRequest::COMPLETED;
        $P = BorrowerTaskVerificationRequest::PROCESSING;

        $this->submissions($this->task('Uline'), $R, $V);                  // fixed and verified
        $this->submissions($this->task('Quill'), $R, $R, $V);              // two old rejections
        $this->submissions($this->task('Grainger'), $V, $V, $V);           // verified three times
        $this->submissions($this->task('Phone Listing'), $V, $R);          // rejected after a verification
        $this->submissions($this->task('Business Email'), $R, $P);         // resubmitted, in review

        $data = $this->overview();

        $this->assertEqualsCanonicalizing(['Uline', 'Quill', 'Grainger'], $this->titles($data['verified']));
        $this->assertSame(['Phone Listing'], $this->titles($data['rejected']));
        $this->assertSame(['Business Email'], $this->titles($data['processing']));
    }
}
