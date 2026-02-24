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
        
        $tierData = Tier::with(['tasks' => function ($query) {
             $query->orderBy('order', 'asc');
        }, 'tasks.latestBorrowerTierTask' => function ($query) use ($borrower) {
             $query->where('borrower_id', $borrower->id)->whereNull('migrate_task_id');
        },'tasks.vendor'])
        ->orderBy('order', 'asc')->get();

        // Resolve the resource collection into a plain PHP array so we can
        // directly mutate the 'tasks' key for migrated-task tiers below.
        $response = TierResource::collection($tierData)->resolve();

        $migratedTask = MigratedTask::with([
            'task.vendor',
            'task.latestBorrowerTierTask' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id)->whereNotNull('migrate_task_id');
            },
        ])->where('borrower_id', $borrower->id)->get();


        if ($migratedTask->isNotEmpty()) {

            foreach ($response as $key => $res) {
                $newTasks = [];
                foreach ($migratedTask as $mTask) {
                    if ($mTask->tier_to == $res['id']) {

                        /** @var \App\Models\CreditBuilder\BorrowerTaskVerificationRequest|null $borrower_task */
                        $borrower_task = $mTask->task?->latestBorrowerTierTask?->where('migrate_task_id', $mTask->id)->first();

                        $newTasks[] = [
                            'id'                       => $mTask->task->id,
                            'title'                    => $mTask->task->title,
                            'subtitle'                 => $mTask->task->subtitle,
                            'description'              => $mTask->task->description,
                            'status'                   => $borrower_task ? $borrower_task->status : BorrowerTaskVerificationRequest::PENDING,
                            'rejection_reason'         => $borrower_task ? $borrower_task->rejection_reason : null,
                            'completed_at'             => ($borrower_task && $borrower_task->verified_at) ? date('d M Y', strtotime($borrower_task->verified_at)) : null,
                            'url'                      => $mTask->task->url,
                            'logo'                     => $mTask->task->logo ? asset('/' . encodeUrl($mTask->task->logo)) : null,
                            'button_title'             => $mTask->task->button_title,
                            'estimated_completion_days' => $mTask->task->estimated_completion_days,
                            'order'                    => $mTask->task->order,
                            'vendor'                   => CreditBuilderVendorResource::make($mTask->task?->vendor),
                        ];
                    }
                }
                if(count($newTasks)){
                    $response[$key]['tasks'] = $newTasks;
                }
            }
        }

        return ApiResponse::success(
            $response,
            __('borrower.tier_task_list_retrieved')
        );

       
    }

    /**
     * Create task verification requests for the given task IDs.
     *
     * @param array $taskIds
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    public function taskVerifyRequest(array $taskIds, $tierId = null)
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();
        
        $verificationRequests = [];

        DB::beginTransaction();
        try{
            foreach ($taskIds as $taskId) {
            // Get task details to retrieve tier_id
                $task = TierTask::find($taskId);

                if (!$task) {
                    throw new \Exception("Task with ID {$taskId} not found.");
                }

                $status = BorrowerTaskVerificationRequest::PROCESSING;
                $verifiedAt = null;
                $verifiedBy = null;

                if(!$task->manual_verification && $task->related_api_id){
                    $api_details = TaskRelatedApi::where('id', $task->related_api_id)->first();
            
                    if($api_details && $api_details->provider == 'MicroBilt'){
                        try {
                            $strategy = \App\Services\Global\MicroBilt\MicroBiltVerificationFactory::make($api_details->name);

                            if ($strategy) {
                                /** @var \App\Services\Global\MicroBilt\MicroBiltService $microBiltService */
                                $microBiltService = app(MicroBiltService::class);
                                
                                $response = $strategy->execute($microBiltService, $borrower);

                                Log::info("MicroBilt {$api_details->name} Response", ['response' => $response]);

                                if (isset($response['VerificationFlags']['VerificationFlag']) && $response['VerificationFlags']['VerificationFlag'] === 'Y') {
                                    $status = BorrowerTaskVerificationRequest::COMPLETED;
                                    $verifiedAt = now();
                                    $verifiedBy = auth()->id();
                                }
                            }
                        } catch (\Exception $e) {
                            Log::error("MicroBilt {$api_details->name} Failed", ['error' => $e->getMessage()]);
                        }
                    }
                }


                $migratable_task = MigratedTask::where('borrower_id',$borrower->id)
                ->where('task_id',$taskId)
                ->where('tier_to',$tierId)
                ->latest()
                ->first();
                
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
                    'migrate_task_id' => $migratable_task ? $migratable_task->id : null,
                ]);

                $verificationRequests[] = $verificationRequest;
            }
            DB::commit();
        }catch(\Exception $e){
            DB::rollBack();
            throw $e;
        }

        return collect($verificationRequests);
    }
}

