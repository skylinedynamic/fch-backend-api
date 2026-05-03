<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Borrower\MigratedTask;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TaskVerificationService
{
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }
    /**
     * Update task status for a borrower.
     */
    public function updateStatus(array $data)
    {
        $tasksData = (array) $data['tasks'];
        $borrowerId = $data['borrower_id'];
        $tierId = $data['tier_id'];
        $statusToSet = $data['status'];
        $note = $data['note'] ?? null;
        $isApi = filter_var($data['is_api'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $borrower = Borrower::with('tier')->find($borrowerId);
        if (!$borrower) {
            throw new \Exception(__('borrower.not_found'));
        }

        $results = [];

        DB::beginTransaction();
        try {
            foreach ($tasksData as $item) {
                $taskId = $item['task_id'];
                $currentMigratedTaskId = $item['migrated_task_id'] ?? null;

                $task = TierTask::with('api')->find($taskId);
                if (!$task)
                    continue;

                $taskName = $task->title ?? 'Unknown Task';
                $tier = $task->tier;

                // Find or create the verification request (INSERT pattern)
                $verificationRequest = BorrowerTaskVerificationRequest::where('borrower_id', $borrowerId)
                    ->where('task_id', $taskId)
                    ->where('tier_id', $tierId)
                    ->when($currentMigratedTaskId, function ($query) use ($currentMigratedTaskId) {
                        return $query->where('migrated_task_id', $currentMigratedTaskId);
                    })
                    ->latest()
                    ->first();

                if (!$verificationRequest) {
                    $verificationRequest = BorrowerTaskVerificationRequest::create([
                        'borrower_id' => $borrowerId,
                        'partner_id' => $borrower->partner_id,
                        'tier_id' => $tierId,
                        'task_id' => $taskId,
                        'status' => BorrowerTaskVerificationRequest::PENDING,
                        'verified_by' => null,
                        'verified_at' => null,
                        'note' => null,
                        'migrated_task_id' => $currentMigratedTaskId,
                    ]);
                }

                // UPDATE pattern (from VerificationCenterService)
                if ($verificationRequest->status == BorrowerTaskVerificationRequest::COMPLETED) {
                    $results[] = [
                        'task_name' => $taskName,
                        'is_verified' => true,
                        'message' => __('verification_center.already_verified'),
                        'skipped' => true,
                    ];
                    continue;
                }

                $shouldTriggerTierUpdate = ($statusToSet == BorrowerTaskVerificationRequest::COMPLETED && $verificationRequest->status != BorrowerTaskVerificationRequest::COMPLETED);

                $verificationRequest->update([
                    'status' => $statusToSet,
                    'verified_by' => Auth::id(),
                    'verified_at' => now(),
                    'note' => $note,
                ]);

                if ($shouldTriggerTierUpdate) {
                    $this->tierUpdate($verificationRequest);
                }

                $isVerified = ($statusToSet == BorrowerTaskVerificationRequest::COMPLETED);
                $results[] = [
                    'task_name' => $taskName,
                    'is_verified' => $isVerified,
                    'message' => $isVerified ? __('verification_center.verified') : __('verification_center.rejected'),
                ];
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('TaskVerificationService: updateStatus failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->sendVerificationStatusMail($borrower, $statusToSet, $note, $results);

        $this->logTaskVerificationActivity($borrower, $results, $statusToSet, $note, $tierId);

        return $results;
    }

    /**
     * Log activity for task verification.
     */
    protected function logTaskVerificationActivity($borrower, array $results, string $statusToSet, ?string $note, $tierId): void
    {
        $processedTasks = array_filter($results, fn($r) => !($r['skipped'] ?? false));
        if (empty($processedTasks))
            return;

        $tier = Tier::find($tierId);
        $tierName = $tier->name ?? 'Unknown Tier';

        $taskSummary = collect($processedTasks)
            ->map(function ($r) {
                $statusColor = $r['is_verified'] ? 'text-success' : 'text-danger';
                return "<li>{$r['task_name']}: <strong class=\"{$statusColor}\">{$r['message']}</strong></li>";
            })
            ->implode("");

        $description = __('verification_center.tasks_processed_desc', ['tier' => $tierName]) . "<ul class='mb-0'>{$taskSummary}</ul>";
        if ($note) {
            $description .= "<br/><strong>" . __('verification_center.note_label') . ":</strong> {$note}";
        }

        $this->activityLogService->logModelEvent(
            $borrower,
            ActivityLog::LOG_TYPE_TASK_VERIFICATION,
            [
                'results' => $results,
                'note' => $note,
                'status' => $statusToSet,
                'category' => 'task',
                'no_image' => true
            ],
            __('verification_center.tier_task_updated'),
            $description
        );
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
            if (!$email)
                return;

            $filteredResults = array_filter($taskResults, fn($r) => !($r['skipped'] ?? false));
            if (empty($filteredResults))
                return;

            $statusLabel = $status == BorrowerTaskVerificationRequest::COMPLETED ? __('verification_center.verified') : __('verification_center.rejected');

            Mail::to($email)->send(new BorrowerVerificationStatusMail([
                'subject' => __('verification_center.verification_status_update') . ' - ' . $statusLabel,
                'borrower_name' => trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) ?: 'Borrower',
                'status' => $status,
                'note' => $note,
                'task_results' => $filteredResults,
            ]));
        } catch (\Exception $e) {
            Log::error('TaskVerificationService: Mail failed', ['error' => $e->getMessage()]);
        }
    }
}
