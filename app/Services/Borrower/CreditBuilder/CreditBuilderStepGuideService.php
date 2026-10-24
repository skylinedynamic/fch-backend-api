<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\TaskRelatedApi;
use App\Services\Global\MicroBilt\MicroBiltService;
use App\Services\Global\MicroBilt\MicroBiltVerificationFactory;
use App\Http\Resources\Borrower\CreditBuilder\TaskVerificationResource;
use App\Http\Resources\Borrower\CreditBuilder\TierResource;
use App\Http\Resources\FCH\CreditBuilder\CreditBuilderVendorResource;
use App\Models\Borrower\MigratedTask;
use App\Services\CreditBuilder\VerificationNotifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditBuilderStepGuideService
{
    /**
     * Get tier wise task list with status.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTierWiseTaskList()
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        $tierData = Tier::with([
            'tasks' => function ($query) {
                $query->orderBy('order', 'asc');
            },
            'tasks.latestBorrowerTierTask' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id)->whereNull('migrated_task_id');
            },
            'tasks.vendor'
        ])
            ->orderBy('order', 'asc')->get();

        // Resolve the resource collection into a plain PHP array so we can
        // directly mutate the 'tasks' key for migrated-task tiers below.
        $response = TierResource::collection($tierData)->resolve();

        $migratedTask = MigratedTask::with([
            'task.vendor',
            'task.latestBorrowerTierTask' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id)->whereNotNull('migrated_task_id');
            },
        ])->where('borrower_id', $borrower->id)->get();


        if ($migratedTask->isNotEmpty()) {
            // The same latest-request lookup the Overview's progress counts with.
            $board = new BorrowerTaskBoard($borrower);

            foreach ($response as $key => $res) {
                $newTasks = [];
                foreach ($migratedTask as $mTask) {
                    if ($mTask->tier_to == $res['id']) {

                        $borrower_task = $board->migratedRequest($mTask->id);

                        $newTasks[] = [
                            'id' => $mTask->task->id,
                            'title' => $mTask->task->title,
                            'subtitle' => $mTask->task->subtitle,
                            'description' => $mTask->task->description,
                            'status' => $borrower_task ? $borrower_task->status : BorrowerTaskVerificationRequest::PENDING,
                            'note' => $borrower_task ? $borrower_task->note : null,
                            'completed_at' => ($borrower_task && $borrower_task->verified_at) ? date('d M Y', strtotime($borrower_task->verified_at)) : null,
                            'url' => $mTask->task->url,
                            'logo' => $mTask->task->logo ? asset('/' . encodeUrl($mTask->task->logo)) : null,
                            'button_title' => $mTask->task->button_title,
                            'estimated_completion_days' => $mTask->task->estimated_completion_days,
                            'order' => $mTask->task->order,
                            'vendor' => CreditBuilderVendorResource::make($mTask->task?->vendor),
                            'migrated_task_id' => $mTask->id,
                        ];
                    }
                }
                if (count($newTasks)) {
                    $response[$key]['tasks'] = $newTasks;
                }
            }
        }

        return ApiResponse::success($response, __('borrower.tier_task_list_retrieved'));
    }

    /**
     * Create task verification requests for the given task IDs.
     * @param array $taskIds, 
     * @param int|null $tierId, 
     * @param int|null $migratedTaskId
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    public function taskVerifyRequest(array $taskIds, $tierId = null, $migratedTaskId = null)
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        $verificationRequests = [];

        DB::beginTransaction();
        try {
            foreach ($taskIds as $taskId) {
                // Get task details to retrieve tier_id
                $task = TierTask::find($taskId);

                if (!$task) {
                    throw new \Exception("Task not found.");
                }

                $status = BorrowerTaskVerificationRequest::PROCESSING;
                $verifiedAt = null;
                $verifiedBy = null;

                $is_task_exist = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                    ->where('task_id', $taskId)
                    ->where('tier_id', $tierId)
                    ->when($migratedTaskId, function ($query) use ($migratedTaskId) {
                        $query->where('migrated_task_id', $migratedTaskId);
                    })
                    ->where('status', BorrowerTaskVerificationRequest::COMPLETED)
                    ->latest()
                    ->first();

                if ($is_task_exist) {
                    throw new \Exception("Task already completed.");
                } else {
                    // Create new request
                    $verificationRequest = BorrowerTaskVerificationRequest::create([
                        'borrower_id' => $borrower->id,
                        'partner_id' => $borrower->partner_id,
                        'tier_id' => $tierId,
                        'task_id' => $taskId,
                        'status' => $status,
                        'verified_by' => $verifiedBy,
                        'verified_at' => $verifiedAt,
                        'rejection_reason' => null,
                        'migrated_task_id' => $migratedTaskId,
                    ]);
                }

                $verificationRequests[] = $verificationRequest;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // An Eloquent collection, not collect(): the notifier call below eager
        // loads with ->load(), which only exists on Eloquent\Collection. A base
        // Support\Collection threw BadMethodCallException right after the commit,
        // so the rows were saved but the host was never told.
        $requests = new EloquentCollection($verificationRequests);

        // Announce the submission to the host, from the host's own mailbox.
        //
        // After the commit, never inside the transaction: a queued mail job that
        // the worker picks up before the rows are visible would read a borrower
        // that does not exist yet. Deliberately not wrapped in the try above
        // either - a mail failure must not roll back a saved request. The
        // notifier swallows and logs its own errors.
        //
        // task and tier are eager-loaded here because the mail lists every task
        // with its tier, and a bulk submission would otherwise be two queries
        // per task.
        app(VerificationNotifier::class)->requestSubmitted(
            $borrower,
            $requests->load('task.tier', 'tier')
        );

        return $requests;
    }
}

