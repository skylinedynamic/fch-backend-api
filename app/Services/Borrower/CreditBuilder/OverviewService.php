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
     * Get credit builder overview data for the signed-in borrower.
     *
     * @return array
     */
    public function myProgress(): array
    {
        return $this->progressFor(Borrower::with('tier')->where('id', auth()->user()->entity_id)->firstOrFail());
    }

    /**
     * A borrower's Credit Builder progress - the Overview's, and the admin's
     * view of the same client (TierService::getBorrowerProgress), so the two
     * and the Step-By-Step Guide always agree (BUG-024).
     */
    public function progressFor(Borrower $borrower): array
    {
        $currentTier = $borrower->tier;
        $tiers = Tier::orderBy('order', 'asc')->withCount('tasks')->get();

        // Counted from the tasks the Step-By-Step Guide lists, by their latest status.
        $board = new BorrowerTaskBoard($borrower);

        $overall = $board->count($tiers);
        $totalCompletedTasks = $overall['completed'];
        $totalTasksRemaining = max(0, $overall['total'] - $totalCompletedTasks);
        $totalCompletePercentage = $overall['total'] > 0 ? round(($totalCompletedTasks / $overall['total']) * 100) : 0;

        // The current tier, out of the tasks it needs (task_to_complete).
        $tier = $currentTier
            ? $board->tierProgress($currentTier)
            : ['completed' => 0, 'remaining' => 0, 'percentage' => 0, 'estimated_days' => 0];

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
            'tasks_remaining_count' => $tier['remaining'],
            'current_tier_completed_tasks' => $tier['completed'],
            'task_complete_percentage' => $tier['percentage'],
            'total_tasks_remaining' => $totalTasksRemaining,
            'total_complete_percentage' => $totalCompletePercentage,
            'current_tier_estimated_completion_days' => $tier['estimated_days'],
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
