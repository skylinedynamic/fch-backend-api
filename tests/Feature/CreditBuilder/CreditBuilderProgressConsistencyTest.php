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

    // ── "Tasks to complete" (BUG-024, retest 25 Oct) ──────────────────
    //
    // Tier 0's tasks must all be done. From Tier 1 on a tier needs only
    // task_to_complete of them (1): doing one opens the next tier and the task
    // moves into it. The Overview said "0/8" for Tier 1 while the Guide said
    // "0/1" - the tier is out of its required tasks, not all it lists.

    private function requireTasks(int $order, ?int $required, array $days = []): void
    {
        $this->tiers[$order]->update(['task_to_complete' => $required]);
        foreach ($days as $i => $d) {
            $this->tasks[$order][$i]->update(['estimated_completion_days' => $d]);
        }
    }

    private function adminProgress(): array
    {
        $this->actingAsUser($this->makePartnerAdmin(Partner::where('is_host', true)->firstOrFail()));
        $data = $this->postJson('/api/v1/admin/credit-builder/tiers/borrowers/progress', ['borrower_id' => $this->borrower->id])->assertOk()->json('data');
        $this->actingAsUser($this->me);

        return $data;
    }

    #[Test]
    public function a_tier_that_needs_one_task_is_out_of_one_not_all_it_lists(): void
    {
        $this->requireTasks(1, 1);
        $this->onTier(1);

        $p = $this->progress();
        $this->assertSame(0, $p['current_tier_complete_task']);
        $this->assertSame(1, $p['current_tier_tasks_remaining'], '0/1 as in the Guide, not 0/2');
        $this->assertEquals(0, $p['current_tier_complete_percentage']);
    }

    #[Test]
    public function doing_more_than_the_tier_needs_shows_it_complete_not_more_than_complete(): void
    {
        // The last tier: three tasks migrated in, two verified, one needed.
        $this->requireTasks(2, 1);
        $this->onTier(2);
        foreach ([0, 1] as $i) {
            $this->verify($this->tasks[0][$i], Verification::COMPLETED, $this->migrate($this->tasks[0][$i], 2));
        }
        $this->migrate($this->tasks[0][2], 2);

        $p = $this->progress();
        $this->assertSame(1, $p['current_tier_complete_task'], '1/1, not 2/1 or 2/3');
        $this->assertSame(0, $p['current_tier_tasks_remaining']);
        $this->assertEquals(100, $p['current_tier_complete_percentage']);
        $this->assertSame(0, $p['current_tier_estimated_completion_days'], 'nothing left to do');
    }

    #[Test]
    public function tier_zero_still_needs_every_task(): void
    {
        $this->requireTasks(0, null);
        $this->onTier(0);
        $this->verify($this->tasks[0][0]);

        $p = $this->progress();
        $this->assertSame(1, $p['current_tier_complete_task']);
        $this->assertSame(2, $p['current_tier_tasks_remaining']);
    }

    #[Test]
    public function the_estimate_is_the_longest_open_task_not_their_sum(): void
    {
        // Tier 0 (every task needed): 5, 15 and 10 days; the 15-day one is done.
        $this->requireTasks(0, null, [5, 15, 10]);
        $this->onTier(0);
        $this->verify($this->tasks[0][1]);
        $this->assertSame(10, $this->progress()['current_tier_estimated_completion_days'], 'not 5 + 10');

        // Tier 1 (one needed): 30 and 45 days, none done.
        $this->requireTasks(1, 1, [30, 45]);
        $this->onTier(1);
        $this->assertSame(45, $this->progress()['current_tier_estimated_completion_days'], 'not 75');
    }

    #[Test]
    public function the_admin_sees_the_progress_the_client_sees(): void
    {
        $this->requireTasks(1, 1, [30, 45]);
        $this->onTier(1);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][0], Verification::REJECTED); // resubmitted: counts once, by its latest status
        $this->verify($this->tasks[0][1]);

        $client = $this->progress();
        $admin = $this->adminProgress();

        foreach (['current_tier_complete_task', 'current_tier_tasks_remaining', 'current_tier_complete_percentage',
            'current_tier_estimated_completion_days', 'total_complete_task', 'total_tasks_remaining', 'total_complete_percentage'] as $field) {
            $this->assertEquals($client[$field], $admin[$field], "admin {$field} differs from the client's");
        }
    }

    // ── Retest 26 Oct: one rule for the rows and the total ────────────
    //
    // The Guide's rows count the tasks each tier needs (all of Tier 0, then
    // task_to_complete), but the total counted every task listed: "7 of 16"
    // over rows adding up to 12. And a tier with nothing listed yet read 0/0 on
    // the Overview, 0/1 in the Guide.

    #[Test]
    public function a_tier_with_no_tasks_yet_still_needs_its_one(): void
    {
        // Tier 2 has no tasks of its own and none migrated into it yet.
        $this->requireTasks(2, 1);
        $this->onTier(2);

        $p = $this->progress();
        $this->assertSame(0, $p['current_tier_complete_task']);
        $this->assertSame(1, $p['current_tier_tasks_remaining'], '0/1 as in the Guide, not 0/0');
        $this->assertEquals(0, $p['current_tier_complete_percentage']);
    }

    #[Test]
    public function the_total_is_the_sum_of_what_each_tier_needs(): void
    {
        // Tier 0: 3 tasks, all needed, 2 done. Tier 1: 2 tasks, 1 needed, none
        // done. Tier 2: nothing listed, nothing set. Rows 2/3 + 0/1 = 2/4 -
        // counting every listed task would say 2/5.
        $this->requireTasks(1, 1);
        $this->onTier(1);
        $this->verify($this->tasks[0][0]);
        $this->verify($this->tasks[0][1]);

        $p = $this->progress();
        $this->assertSame(2, $p['total_complete_task']);
        $this->assertSame(2, $p['total_tasks_remaining'], 'out of 4 needed, not 5 listed');
        $this->assertEquals(50, $p['total_complete_percentage']);
    }

    #[Test]
    public function the_total_never_goes_over_100_percent(): void
    {
        // More done than a tier needs counts as that tier done, once.
        $this->requireTasks(0, null);
        $this->requireTasks(1, 1);
        $this->requireTasks(2, 1);
        $this->onTier(2);
        foreach ($this->tasks[0] as $t) {
            $this->verify($t);
        }
        $this->verify($this->tasks[1][0]);
        $this->verify($this->tasks[1][1]);
        $this->verify($this->tasks[0][0], Verification::COMPLETED, $this->migrate($this->tasks[0][0], 2));

        $p = $this->progress();
        $this->assertSame(5, $p['total_complete_task'], '3 + 1 + 1, not 3 + 2 + 1');
        $this->assertSame(0, $p['total_tasks_remaining']);
        $this->assertEquals(100, $p['total_complete_percentage']);
        $this->assertEquals($p['total_complete_percentage'], $this->adminProgress()['total_complete_percentage']);
    }
}
