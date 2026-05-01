<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Borrower\MigratedTask;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TaskVerificationService
{
    /**
     * Update task status for a borrower.
     */
    public function updateStatus(array $data)
    {
        $taskIds = (array) $data['task_ids'];
        $borrowerId = $data['borrower_id'];
        $statusToSet = $data['status'];
        $note = $data['note'] ?? null;

        $borrower = Borrower::with('tier')->find($borrowerId);
        if (!$borrower) {
            throw new \Exception(__('borrower.not_found'));
        }

        $results = [];

        foreach ($taskIds as $taskId) {
            $task = TierTask::find($taskId);
            if (!$task) continue;   

            $taskName = $task->title ?? 'Unknown Task';

            // Find or create the verification request
           $verificationRequest = BorrowerTaskVerificationRequest::where('borrower_id', $borrowerId)
                ->where('task_id', $taskId)
                ->latest() // usually orders by created_at desc
                ->first();

            if (!$verificationRequest) {
                $verificationRequest = BorrowerTaskVerificationRequest::create([
                    'borrower_id' => $borrowerId,
                    'task_id' => $taskId,
                    'partner_id' => $borrower->partner_id,
                    'tier_id' => $task->tier_id,
                    'status' => BorrowerTaskVerificationRequest::PENDING,
                ]);
            }   

            // Skip if already completed or rejected (business rule)
            if (in_array($verificationRequest->status, [BorrowerTaskVerificationRequest::COMPLETED, BorrowerTaskVerificationRequest::REJECTED])) {
                 $results[] = [
                    'task_name'   => $taskName,
                    'is_verified' => ($verificationRequest->status == BorrowerTaskVerificationRequest::COMPLETED),
                    'message'     => ($verificationRequest->status == BorrowerTaskVerificationRequest::COMPLETED) 
                                     ? __('verification_center.already_verified') 
                                     : __('verification_center.already_rejected'),
                    'skipped'     => true,
                ];
                continue;
            }

            // Update status
            $verificationRequest->update([
                'status'      => $statusToSet,
                'verified_by' => Auth::id(),
                'verified_at' => now(),
                'note'        => $note,
            ]);

            // Trigger tier update if completed
            if ($statusToSet == BorrowerTaskVerificationRequest::COMPLETED) {
                $this->tierUpdate($verificationRequest);
            }

            $isVerified = ($statusToSet == BorrowerTaskVerificationRequest::COMPLETED);
            $results[] = [
                'task_name'   => $taskName,
                'is_verified' => $isVerified,
                'message'     => $isVerified ? __('verification_center.verified') : __('verification_center.rejected'),
            ];
        }

        // Send consolidated mail
        $this->sendVerificationStatusMail($borrower, $statusToSet, $note, $results);

        return $results;
    }

    /**
     * Tier progression logic.
     */
    protected function tierUpdate(BorrowerTaskVerificationRequest $verificationRequest)
    {
        $tierId = $verificationRequest->tier_id;
        $borrowerId = $verificationRequest->borrower_id;
        $taskTier = Tier::find($tierId);

        if (!$taskTier || $taskTier->code == 'T4') {
            return;
        }

        $borrower = Borrower::with('tier')->find($borrowerId);

        // Migrated task logic
        if ($taskTier->code != 'T0') {
            $task = $verificationRequest->task;
            MigratedTask::create([
                'borrower_id' => $borrowerId,
                'partner_id' => $verificationRequest->partner_id,
                'task_id' => $task->id,
                'vendor_id' => $task->vendor_id,
                'tier_from' => $tierId,
                'tier_to' => $tierId + 1,
                'migrated_by' => Auth::id(),
                'status' => BorrowerTaskVerificationRequest::PENDING
            ]);
        }

        // Check if all/required tasks are completed for tier upgrade
        $totalTasks = TierTask::where('tier_id', $tierId)->count();
        $verifiedTasks = BorrowerTaskVerificationRequest::where('borrower_id', $borrowerId)
            ->where('tier_id', $tierId)
            ->where('status', BorrowerTaskVerificationRequest::COMPLETED)
            ->count();

        $requiredTasks = !is_null($taskTier->task_to_complete) ? $taskTier->task_to_complete : $totalTasks;

        if ($verifiedTasks >= $requiredTasks) {
            $nextTier = Tier::where('order', '>', $borrower->tier->order)
                ->orderBy('order', 'asc')
                ->first();

            if ($nextTier && ($taskTier->code == 'T0' || $borrower->current_tier <= $taskTier->id)) {
                $borrower->update(['current_tier' => $nextTier->id]);
            }
        }
    }

    /**
     * Mail notification.
     */
    protected function sendVerificationStatusMail($borrower, string $status, ?string $note, array $taskResults): void
    {
        try {
            $email = $borrower?->email ?? $borrower?->user?->email ?? null;
            if (!$email) return;

            $filteredResults = array_filter($taskResults, fn($r) => !($r['skipped'] ?? false));
            if (empty($filteredResults)) return;

            $statusLabel = $status == BorrowerTaskVerificationRequest::COMPLETED ? __('verification_center.verified') : __('verification_center.rejected');

            Mail::to($email)->send(new BorrowerVerificationStatusMail([
                'subject'      => __('verification_center.verification_status_update') . ' - ' . $statusLabel,
                'borrower_name' => trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) ?: 'Borrower',
                'status'       => $status,
                'note'         => $note,
                'task_results' => $filteredResults,
            ]));
        } catch (\Exception $e) {
            Log::error('TaskVerificationService: Mail failed', ['error' => $e->getMessage()]);
        }
    }
}
