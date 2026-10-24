<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\Limit\CreditLine;
use Illuminate\Support\Facades\Auth;

class OverviewService
{
    /**
     * Get credit builder overview data.
     *
     * @return array
     */
    public function myProgress(): array
    {
        $borrower = Borrower::with('tier')->where('id',auth()->user()->entity_id)->first();
        $currentTier = $borrower->tier; 
        $tiers = Tier::orderBy('order', 'asc')->withCount('tasks')->get();

        // Counted from the tasks the Step-By-Step Guide lists, by their latest
        // status, so the Overview and the Guide always agree (BUG-024).
        $board = new BorrowerTaskBoard($borrower);

        $overall = $board->count($tiers->pluck('id'));
        $totalCompletedTasks = $overall['completed'];
        $totalTasksRemaining = max(0, $overall['total'] - $totalCompletedTasks);
        $totalCompletePercentage = $overall['total'] > 0 ? round(($totalCompletedTasks / $overall['total']) * 100) : 0;

        // Current Tier Stats
        $currentTierCompletedTasks = 0;
        $tasksRemaining = 0;
        $taskCompletePercentage = 0;
        $currentTierEstimatedDays = 0;

        if ($currentTier) {
            $tierTasks = $board->tierTasks($currentTier->id);
            $openTasks = $tierTasks->where('status', '!==', BorrowerTaskVerificationRequest::COMPLETED);

            $currentTierCompletedTasks = $tierTasks->count() - $openTasks->count();
            $tasksRemaining = $openTasks->count();
            $currentTierEstimatedDays = $openTasks->sum('estimated_completion_days');

            // A tier with no tasks at all is effectively complete.
            $taskCompletePercentage = $tierTasks->isNotEmpty()
                ? round(($currentTierCompletedTasks / $tierTasks->count()) * 100)
                : 100;
        }

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
            'tasks_remaining_count' => $tasksRemaining,
            'current_tier_completed_tasks' => $currentTierCompletedTasks,
            'task_complete_percentage' => $taskCompletePercentage,
            'total_tasks_remaining' => $totalTasksRemaining,
            'total_complete_percentage' => $totalCompletePercentage,
            'current_tier_estimated_completion_days' => (int) $currentTierEstimatedDays,
            'total_completed_tasks' => $totalCompletedTasks,
        ];
    }

    /**
     * Get task overview for the current tier.
     *
     * @return array
     */
    public function taskOverview(): array
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();
        $currentTier = $borrower->tier;

        if (!$currentTier) {
            // Same shape as below: TaskOverviewResource reads 'requests'.
            return [
                'tier' => null,
                'requests' => collect([]),
            ];
        }

        $requests = BorrowerTaskVerificationRequest::with('task','tier')->where('borrower_id', $borrower->id)->get();

        return [
            'tier' => $currentTier,
            'requests' => $requests,
        ];
    }
}
