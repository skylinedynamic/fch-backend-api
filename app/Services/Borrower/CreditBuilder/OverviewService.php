<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTierTask;
use App\Models\CreditBuilder\Tier;
use App\Models\Limit\CreditLine;

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
        $tiers = Tier::orderBy('order', 'asc')->get();

        $tasksRemaining = 0;
        $taskCompletePercentage = 0;

        if ($currentTier) {
            $totalTasks = $currentTier->tasks()->count();
            
            // Count completed tasks for this borrower in this tier
            $completedTasks = BorrowerTierTask::where('borrower_id', $borrower->id)
                ->where('tier_id', $currentTier->id)
                ->where('status', BorrowerTierTask::COMPLETED)
                ->count();

            $tasksRemaining = max(0, $totalTasks - $completedTasks);
            
            if ($totalTasks > 0) {
                $taskCompletePercentage = round(($completedTasks / $totalTasks) * 100);
            } else {
                $taskCompletePercentage = 100; // If no tasks, technically 100% done? Or 0? Usually 100 implies tier complete.
            }
        }

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
            'tasks_remaining_count' => $tasksRemaining,
            'task_complete_percentage' => $taskCompletePercentage,
        ];
    }


}
