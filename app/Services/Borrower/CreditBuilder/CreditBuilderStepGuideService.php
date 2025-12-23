<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;

class CreditBuilderStepGuideService
{
    /**
     * Get tier wise task list with status.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTierWiseTaskList()
    {
        $borrower = Borrower::where('id', auth()->user()->entity_id)->first();
        
        return Tier::with(['tasks' => function ($query) {
             $query->orderBy('order', 'asc');
        }, 'tasks.borrowerTierTasks' => function ($query) use ($borrower) {
             $query->where('borrower_id', $borrower->id);
        }])->orderBy('order', 'asc')->get();
    }

    /**
     * Create task verification requests for the given task IDs.
     *
     * @param array $taskIds
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function taskVerifyRequest(array $taskIds)
    {
        $borrower = Borrower::where('id', auth()->user()->entity_id)->first();
        
        // Get existing verification requests for this borrower
        $existingTaskIds = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
            ->whereIn('task_id', $taskIds)
            ->pluck('task_id')
            ->toArray();
        
        // Filter out task IDs that already have verification requests
        $newTaskIds = array_diff($taskIds, $existingTaskIds);
        
        $verificationRequests = [];
        
        foreach ($newTaskIds as $taskId) {
            // Get task details to retrieve tier_id
            $task = TierTask::find($taskId);
            
            if ($task) {
                $verificationRequest = BorrowerTaskVerificationRequest::create([
                    'borrower_id' => $borrower->id,
                    'partner_id' => $borrower->partner_id,
                    'tier_id' => $task->tier_id,
                    'task_id' => $taskId,
                    'status' => BorrowerTaskVerificationRequest::PROCESSING,
                ]);
                
                $verificationRequests[] = $verificationRequest;
            }
        }
        
        return collect($verificationRequests);
    }
}

