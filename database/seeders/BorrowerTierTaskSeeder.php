<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTierTask;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Database\Seeder;

class BorrowerTierTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $borrowers = Borrower::with('tier')->get();
        $tiers = Tier::with('tasks')->get()
            ->sortBy('order')
            ->values(); 

        if ($borrowers->isEmpty() || $tiers->isEmpty()) {
            return;
        }

        foreach ($borrowers as $borrower) {
            $currentTier = $borrower->tier;

            // SPECIAL CASE FOR BORROWER 2: Set to first tier
            if ($borrower->id == 2 && $tiers->isNotEmpty()) {
                $firstTier = $tiers->first();
                $borrower->current_tier = $firstTier->id;
                $borrower->save();
                $currentTier = $firstTier;
            }

            $currentTierOrder = $currentTier ? $currentTier->order : 1;

            foreach ($tiers as $tier) {
                if ($tier->order > $currentTierOrder) {
                    continue;
                }

                $tasks = $tier->tasks->sortBy('order');
                
                try {
                    if ($tier->order < $currentTierOrder) {
                        foreach ($tasks as $task) {
                            BorrowerTierTask::firstOrCreate(
                                [
                                    'borrower_id' => $borrower->id,
                                    'tier_task_id' => $task->id,
                                ],
                                [
                                    'tier_id' => $tier->id,
                                    'status' => BorrowerTierTask::COMPLETED,
                                    'completed_at' => now(),
                                ]
                            );
                        }
                    } 
                       // Current Tier: Partial Completion
                    elseif ($tier->order == $currentTierOrder) {
                        // SPECIAL CASE FOR BORROWER 2: Complete ALL tasks in current tier
                        if ($borrower->id == 2) {
                            foreach ($tasks as $task) {
                                BorrowerTierTask::firstOrCreate(
                                    [
                                        'borrower_id' => $borrower->id,
                                        'tier_task_id' => $task->id,
                                    ],
                                    [
                                        'tier_id' => $tier->id,
                                        'status' => BorrowerTierTask::COMPLETED,
                                        'completed_at' => now(),
                                    ]
                                );
                            }
                            continue;
                        }

                        // Determine random progress: 0 to (total tasks - 1) completed
                        // We assumes if they are in this tier, they haven't finished it yet.
                        $totalTasks = $tasks->count();
                        if ($totalTasks > 0) {
                            $completedCount = rand(0, $totalTasks - 1);
                            $currentIndex = 0;

                            foreach ($tasks as $task) {
                                if ($currentIndex < $completedCount) {
                                    // Completed tasks
                                    BorrowerTierTask::firstOrCreate(
                                        [
                                            'borrower_id' => $borrower->id,
                                            'tier_task_id' => $task->id,
                                        ],
                                        [
                                            'tier_id' => $tier->id,
                                            'status' => BorrowerTierTask::COMPLETED,
                                            'completed_at' => now(),
                                        ]
                                    );
                                } elseif ($currentIndex == $completedCount) {
                                    // The single Pending task
                                    BorrowerTierTask::firstOrCreate(
                                        [
                                            'borrower_id' => $borrower->id,
                                            'tier_task_id' => $task->id,
                                        ],
                                        [
                                            'tier_id' => $tier->id,
                                            'status' => BorrowerTierTask::PENDING,
                                        ]
                                    );
                                    // Stop creating tasks after the pending one
                                    break;
                                }
                                $currentIndex++;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    echo "Error processing Borrower ID {$borrower->id}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}
