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
        $borrower = Borrower::where('id', auth()->user()->entity_id)->first();
        
        return Tier::with(['tasks' => function ($query) {
             $query->orderBy('order', 'asc');
        }, 'tasks.latestBorrowerTierTasks' => function ($query) use ($borrower) {
             $query->where('borrower_id', $borrower->id);
        },'tasks.vendor'])
        ->orderBy('order', 'asc')->get();
    }

    /**
     * Create task verification requests for the given task IDs.
     *
     * @param array $taskIds
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    public function taskVerifyRequest(array $taskIds)
    {
        $borrower = Borrower::where('id', auth()->user()->entity_id)->first();
        
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
                
                // Create new request
                $verificationRequest = BorrowerTaskVerificationRequest::create([
                    'borrower_id' => $borrower->id,
                    'partner_id' => $borrower->partner_id,
                    'tier_id' => $task->tier_id,
                    'task_id' => $taskId,
                    'status' => $status,
                    'verified_by' => $verifiedBy,
                    'verified_at' => $verifiedAt,
                    'rejection_reason' => null,
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

