<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\MigratedTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest as Verification;
use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-024: Credit Builder › Overview (my-progress) and › Step-By-Step Guide
 * (tier-wise-tasklist) must tell the borrower the same progress. Both count
 * the tasks the Guide lists - a tier the borrower had tasks migrated into
 * lists those instead of its own - with each task's latest status.
 */
class CreditBuilderProgressConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;

    private User $me;

    private Borrower $borrower;

    /** @var array<int, Tier> */
    private array $tiers = [];

    /** @var array<int, array<int, TierTask>> tier order => its tasks */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->borrower = Borrower::findOrFail($this->me->entity_id);

        // Tier 0: 3 tasks, Tier 1: 2 tasks, Tier 2: none of its own.
        foreach ([0 => 3, 1 => 2, 2 => 0] as $order => $count) {
            $this->tiers[$order] = Tier::create([
                'name' => "Tier {$order}", 'title' => "Tier {$order}", 'code' => "T{$order}",
                'status' => 'Active', 'order' => $order,
            ]);
            $this->tasks[$order] = [];
            for ($i = 1; $i <= $count; $i++) {
                $this->tasks[$order][] = TierTask::create([
                    'tier_id' => $this->tiers[$order]->id, 'title' => "Task {$order}.{$i}",
                    'status' => 'Active', 'order' => $i, 'estimated_completion_days' => 1,
                ]);
            }
        }

        $this->actingAsUser($this->me);
    }

    private function onTier(int $order): void
    {
        $this->borrower->update(['current_tier' => $this->tiers[$order]->id]);
    }

    private function verify(TierTask $task, string $status = Verification::COMPLETED, ?MigratedTask $migrated = null): void
    {
        $request = Verification::create([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'tier_id' => $migrated ? $migrated->tier_to : $task->tier_id,
            'task_id' => $task->id,
            'status' => $status,
            'migrated_task_id' => $migrated?->id,
        ]);
        // Each new request is the task's latest.
        $request->forceFill(['created_at' => now()->addSeconds(Verification::count())])->save();
    }

    private function migrate(TierTask $task, int $toOrder): MigratedTask
    {
        // A migrated task is a vendor's task (migrated_tasks.vendor_id is required).
        $vendor = CreditBuilderVendor::firstOrCreate(['name' => 'Vendor'], ['tier_id' => $task->tier_id, 'status' => 'Active']);
        $task->update(['vendor_id' => $vendor->id]);

        return MigratedTask::create([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'task_id' => $task->id,
            'tier_from' => $task->tier_id,
            'tier_to' => $this->tiers[$toOrder]->id,
            'vendor_id' => $vendor->id,
            'status' => 'Active',
        ]);
    }

    private function progress(): array
    {
        return $this->getJson('/api/v1/borrower/credit-builder/my-progress')->assertOk()->json('data');
    }

    /** What the Guide lists: [done, listed] overall and per tier id. */
    private function guide(): array
    {
        $tiers = $this->getJson('/api/v1/borrower/credit-builder/tier-wise-tasklist')->assertOk()->json('data');
        $done = 0;
        $listed = 0;
        $perTier = [];
        foreach ($tiers as $tier) {
            $tasks = $tier['tasks'] ?? [];
            $tierDone = count(array_filter($tasks, fn ($t) => ($t['status'] ?? null) === Verification::COMPLETED));
            $perTier[$tier['id']] = [$tierDone, count($tasks)];
            $done += $tierDone;
            $listed += count($tasks);
        }

        return ['done' => $done, 'listed' => $listed, 'tiers' => $perTier];
    }

    #[Test]
    public function the_overview_counts_the_current_tiers_completed_tasks(): void
    {
        $this->onTier(0);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][1]);

        $p = $this->progress();
        $this->assertSame(2, $p['current_tier_complete_task'], 'Tasks completed: 2/3, not 0/1');
        $this->assertSame(1, $p['current_tier_tasks_remaining']);
        $this->assertEquals(67, $p['current_tier_complete_percentage']);
    }

    #[Test]
    public function a_verified_task_in_another_tier_does_not_count_for_the_current_one(): void
    {
        $this->onTier(1);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][1]);
        $this->verify($this->tasks[1][0]);

        $p = $this->progress();
        $this->assertSame(1, $p['current_tier_complete_task']);
        $this->assertSame(1, $p['current_tier_tasks_remaining']);
        $this->assertEquals(50, $p['current_tier_complete_percentage']);
    }

    #[Test]
    public function a_tier_made_of_migrated_tasks_reports_their_progress_not_100_percent(): void
    {
        // Tier 2 has no tasks of its own; two were migrated into it, one is verified.
        $this->onTier(2);
        $first = $this->migrate($this->tasks[0][0], 2);
        $this->migrate($this->tasks[0][1], 2);
        $this->verify($this->tasks[0][0], Verification::COMPLETED, $first);

        $p = $this->progress();
        $this->assertSame(1, $p['current_tier_complete_task']);
        $this->assertSame(1, $p['current_tier_tasks_remaining'], 'not "100% · 0/0"');
        $this->assertEquals(50, $p['current_tier_complete_percentage']);
        $this->assertSame([1, 2], $this->guide()['tiers'][$this->tiers[2]->id]);
    }

    #[Test]
    public function the_overview_and_the_guide_agree_on_overall_progress(): void
    {
        // Tier 1's own two tasks are replaced in the Guide by one migrated task.
        $this->onTier(1);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][1]);
        $migrated = $this->migrate($this->tasks[0][2], 1);
        $this->verify($this->tasks[0][2], Verification::COMPLETED, $migrated);

        $p = $this->progress();
        $g = $this->guide();
        $this->assertSame($g['done'], $p['total_complete_task']);
        $this->assertSame($g['listed'], $p['total_complete_task'] + $p['total_tasks_remaining']);
        $this->assertEquals(round($g['done'] / $g['listed'] * 100), $p['total_complete_percentage']);
        // And the current tier matches the Guide's Tier 1 row.
        [$tierDone, $tierListed] = $g['tiers'][$this->tiers[1]->id];
        $this->assertSame($tierDone, $p['current_tier_complete_task']);
        $this->assertSame($tierListed, $p['current_tier_complete_task'] + $p['current_tier_tasks_remaining']);
    }

    #[Test]
    public function the_guide_shows_a_migrated_tasks_latest_status(): void
    {
        // Verified, then re-submitted and rejected: it is not done any more.
        $this->onTier(1);
        $migrated = $this->migrate($this->tasks[0][0], 1);
        $this->verify($this->tasks[0][0], Verification::COMPLETED, $migrated);
        $this->verify($this->tasks[0][0], Verification::REJECTED, $migrated);

        $tiers = $this->getJson('/api/v1/borrower/credit-builder/tier-wise-tasklist')->assertOk()->json('data');
        $tier1 = collect($tiers)->firstWhere('id', $this->tiers[1]->id);
        $this->assertSame(Verification::REJECTED, $tier1['tasks'][0]['status']);
        $this->assertSame(0, $this->progress()['current_tier_complete_task']);
    }

    #[Test]
    public function a_task_counts_by_its_latest_status_as_in_the_guide(): void
    {
        // Verified, then re-submitted and rejected: the Guide shows it rejected.
        $this->onTier(0);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][0], Verification::REJECTED);
        $this->verify($this->tasks[0][1]);

        $p = $this->progress();
        $g = $this->guide();
        $this->assertSame(1, $p['current_tier_complete_task']);
        $this->assertSame($g['done'], $p['total_complete_task']);
    }
}
