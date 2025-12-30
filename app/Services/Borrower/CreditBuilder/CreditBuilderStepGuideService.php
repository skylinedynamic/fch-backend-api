<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\TaskRelatedApi;
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
        
        $verificationRequests = [];
        
        foreach ($taskIds as $taskId) {
            // Get task details to retrieve tier_id
            $task = TierTask::find($taskId);

            if(!$task->manual_verification && $task->related_api_id){
                $api_details = TaskRelatedApi::where('id', $task->related_api_id)->first();
                
                if($api_details && $api_details->provider == 'Microbilt'){
                    if($api_details->name == 'Phone Verification By Address'){
                        try {
                            Log::info('MicroBilt Phone Verification Request', [
                                'borrower' => $borrower,
                                'api_details' => $api_details,
                            ]);
                            /** @var \App\Services\Global\MicroBilt\MicroBiltService $microBiltService */
                            $microBiltService = app(\App\Services\Global\MicroBilt\MicroBiltService::class);
                            
                            $response = $microBiltService->verifyPhoneByAddress(
                                $borrower->business_phone ?? '',
                                $borrower->business_address ?? '',
                                $borrower->business_city ?? '',
                                $borrower->business_state ?? '',
                                $borrower->business_postal_code ?? ''
                            );

                            Log::info('MicroBilt Phone Verification Response', ['response' => $response]);

                        } catch (\Exception $e) {
                            Log::error('MicroBilt Phone Verification Failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            }
            
            if ($task) {
                // Check if request exists
                $existingRequest = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                    ->where('task_id', $taskId)
                    ->first();

                if ($existingRequest) {
                     // Update existing request
                     $existingRequest->update([
                        'status' => BorrowerTaskVerificationRequest::PROCESSING,
                        'rejection_reason' => null,
                        'verified_by' => null,
                        'verified_at' => null,
                     ]);
                     $verificationRequests[] = $existingRequest;
                } else {
                    // Create new request
                    $verificationRequest = BorrowerTaskVerificationRequest::create([
                        'borrower_id' => $borrower->id,
                        'partner_id' => $borrower->partner_id,
                        'tier_id' => $task->tier_id,
                        'task_id' => $taskId,
                        'status' => BorrowerTaskVerificationRequest::PROCESSING,
                        'rejection_reason' => null,
                    ]);
                    $verificationRequests[] = $verificationRequest;
                }
            }
        }
        
        return collect($verificationRequests);
    }
}

