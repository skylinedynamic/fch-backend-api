<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Database\Seeder;

class BorrowerTaskVerificationRequestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get Tier 1
        $tier1 = Tier::where('order', 1)->first();

        if (!$tier1) {
            return;
        }

        // Get all borrowers
        $borrowers = Borrower::all();

        // Get all tasks for Tier 1
        $tier1Tasks = TierTask::where('tier_id', $tier1->id)->get();

        foreach ($borrowers as $borrower) {     
            foreach ($tier1Tasks as $task) {
                BorrowerTaskVerificationRequest::firstOrCreate(
                    [
                        'borrower_id' => $borrower->id,
                        'tier_id' => $tier1->id,
                        'task_id' => $task->id,
                    ],
                    [
                        'partner_id' => $borrower->partner_id,
                        'status' => BorrowerTaskVerificationRequest::PROCESSING,
                        'verified_by' => $borrower->id == 1 ? 1 : null,
                        'verified_at' => $borrower->id == 1 ? now() : null,
                    ]
                );
            }
        }
    }
}
