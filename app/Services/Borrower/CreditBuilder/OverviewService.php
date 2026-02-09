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

        // Total Program Stats
        $totalProgramTasks = $tiers->sum('tasks_count');
        $totalCompletedTasks = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
            ->where('status', BorrowerTaskVerificationRequest::COMPLETED)
            ->count();

        $totalTasksRemaining = max(0, $totalProgramTasks - $totalCompletedTasks);
        $totalCompletePercentage = $totalProgramTasks > 0 ? round(($totalCompletedTasks / $totalProgramTasks) * 100) : 0; // If 0 tasks, 0% complete usually makes more sense unless "done" context

        // Current Tier Stats
        $tasksRemaining = 0;
        $taskCompletePercentage = 0;
        $currentTierEstimatedDays = 0;

        if ($currentTier) {
            $currentTierTotalTasks = $currentTier->tasks()->count();
            
            // Get completed tasks IDs for this borrower in this tier
            $completedTaskIds = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                ->where('tier_id', $currentTier->id)
                ->where('status', BorrowerTaskVerificationRequest::COMPLETED)
                ->pluck('task_id');

            $currentTierCompletedTasks = $completedTaskIds->count();

            $tasksRemaining = max(0, $currentTierTotalTasks - $currentTierCompletedTasks);
            
            $currentTierEstimatedDays = $currentTier->tasks()
                ->whereNotIn('id', $completedTaskIds)
                ->sum('estimated_completion_days');
            
            if ($currentTierTotalTasks > 0) {
                $taskCompletePercentage = round(($currentTierCompletedTasks / $currentTierTotalTasks) * 100);
            } else {
                $taskCompletePercentage = 100; // If current tier has no tasks, it is effectively complete
            }
        }

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
            'tasks_remaining_count' => $tasksRemaining,
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
            return [
                'tier' => null,
                'tasks' => collect([]),
            ];
        }

        $tasks = $currentTier->tasks()
            ->with(['borrowerTierTasks' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id);
            }, 'vendor'])
            ->orderBy('order', 'asc')
            ->get();

        return [
            'tier' => $currentTier,
            'tasks' => $tasks,
        ];
    }
}
