<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\MigratedTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
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
     * Done and listed counts for a set of tiers.
     *
     * @param  iterable<int>  $tierIds
     * @return array{completed: int, total: int}
     */
    public function count(iterable $tierIds): array
    {
        $completed = 0;
        $total = 0;
        foreach ($tierIds as $tierId) {
            $tasks = $this->tierTasks((int) $tierId);
            $total += $tasks->count();
            $completed += $tasks->where('status', BorrowerTaskVerificationRequest::COMPLETED)->count();
        }

        return ['completed' => $completed, 'total' => $total];
    }
}
