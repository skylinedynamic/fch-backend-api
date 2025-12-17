<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;

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
}
