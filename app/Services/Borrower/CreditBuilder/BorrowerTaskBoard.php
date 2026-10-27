<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\MigratedTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Support\Collection;

/**
 * The Credit Builder tasks a borrower is shown, tier by tier, each with its
 * latest verification request - the one list both the Step-By-Step Guide and
 * the Overview's progress are built from, so the two always agree (BUG-024).
 *
 * A tier the borrower had tasks migrated into lists those tasks instead of its
 * own.
 */
class BorrowerTaskBoard
{
    /** @var Collection<int, BorrowerTaskVerificationRequest> task id => latest request on the tier's own task */
    private Collection $ownRequests;

    /** @var Collection<int, BorrowerTaskVerificationRequest> migrated task id => its latest request */
    private Collection $migratedRequests;

    /** @var Collection<int, Collection<int, MigratedTask>> tier id => tasks migrated into it */
    private Collection $migratedByTier;

    /** @var Collection<int, Collection<int, TierTask>> tier id => its own tasks */
    private Collection $ownByTier;

    public function __construct(private Borrower $borrower)
    {
        $requests = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $this->ownRequests = $requests->whereNull('migrated_task_id')->unique('task_id')->keyBy('task_id');
        $this->migratedRequests = $requests->whereNotNull('migrated_task_id')->unique('migrated_task_id')->keyBy('migrated_task_id');

        $this->migratedByTier = MigratedTask::with('task')->where('borrower_id', $borrower->id)->get()->groupBy('tier_to');
        $this->ownByTier = TierTask::orderBy('order')->get()->groupBy('tier_id');
    }

    /** The latest request on a task migrated to the borrower, if any. */
    public function migratedRequest(int $migratedTaskId): ?BorrowerTaskVerificationRequest
    {
        return $this->migratedRequests->get($migratedTaskId);
    }

    /**
     * The tasks shown in a tier, each as ['estimated_completion_days' => int,
     * 'status' => latest request status or null].
     *
     * @return Collection<int, array{estimated_completion_days: int, status: ?string}>
     */
    public function tierTasks(int $tierId): Collection
    {
        $migrated = $this->migratedByTier->get($tierId);

        if ($migrated && $migrated->isNotEmpty()) {
            return $migrated->filter(fn (MigratedTask $m) => $m->task)->map(fn (MigratedTask $m) => [
                'estimated_completion_days' => (int) $m->task->estimated_completion_days,
                'status' => $this->migratedRequests->get($m->id)?->status,
            ])->values();
        }

        return $this->ownByTier->get($tierId, collect())->map(fn (TierTask $task) => [
            'estimated_completion_days' => (int) $task->estimated_completion_days,
            'status' => $this->ownRequests->get($task->id)?->status,
        ])->values();
    }

    /**
     * Where the borrower stands in a tier: out of the tasks it needs, not all it
     * lists (BUG-024). Tier 0 needs every task; from Tier 1 on a tier needs
     * task_to_complete of them - doing one opens the next tier and the task
     * moves into it - so Tier 1 with 8 tasks reads 0/1, never 0/8, and more
     * than needed reads 1/1, never 2/1.
     *
     * A tier with nothing listed yet - its task arrives from the tier below -
     * still needs its task_to_complete (0/1, as in the Guide); one with no
     * requirement and nothing listed has nothing to do.
     *
     * The estimate is the longest task still open: tasks run side by side, so
     * the tier takes as long as its slowest one, not their sum.
     *
     * The client portal's Guide rows (TierAccordion) apply the same rule.
     *
     * @return array{completed: int, required: int, remaining: int, percentage: int, estimated_days: int}
     */
    public function tierProgress(Tier $tier): array
    {
        $tasks = $this->tierTasks($tier->id);
        $needs = (int) $tier->task_to_complete;

        $required = $needs > 0
            ? ($tasks->isNotEmpty() ? min($needs, $tasks->count()) : $needs)
            : $tasks->count();

        if ($required === 0) {
            return ['completed' => 0, 'required' => 0, 'remaining' => 0, 'percentage' => 100, 'estimated_days' => 0];
        }

        $done = $tasks->where('status', BorrowerTaskVerificationRequest::COMPLETED)->count();
        $completed = min($done, $required);
        $remaining = $required - $completed;

        return [
            'completed' => $completed,
            'required' => $required,
            'remaining' => $remaining,
            'percentage' => (int) round($completed / $required * 100),
            'estimated_days' => $remaining > 0
                ? (int) $tasks->where('status', '!==', BorrowerTaskVerificationRequest::COMPLETED)->max('estimated_completion_days')
                : 0,
        ];
    }

    /**
     * Done and needed counts over a set of tiers - the sum of each tier's
     * tierProgress(), so the total is what the Guide's rows add up to and can
     * never pass 100% (BUG-024 retest: "7 of 16" over rows adding up to 12).
     *
     * @param  iterable<Tier>  $tiers
     * @return array{completed: int, total: int}
     */
    public function count(iterable $tiers): array
    {
        $completed = 0;
        $total = 0;
        foreach ($tiers as $tier) {
            $progress = $this->tierProgress($tier);
            $total += $progress['required'];
            $completed += $progress['completed'];
        }

        return ['completed' => $completed, 'total' => $total];
    }
}
