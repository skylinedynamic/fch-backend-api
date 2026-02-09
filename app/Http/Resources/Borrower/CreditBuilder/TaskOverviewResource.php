<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Borrower\CreditBuilder\TierResource;
use App\Http\Resources\Borrower\CreditBuilder\TaskOverviewItemResource;

class TaskOverviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tasks = $this->resource['tasks'];

        return [
            'tier' => TierResource::make($this->resource['tier']),
            'verified' => TaskOverviewItemResource::collection($tasks->filter(function ($task) {
                $verification = $task->borrowerTierTasks->first();
                return $verification && $verification->status === BorrowerTaskVerificationRequest::COMPLETED;
            })),
            'processing' => TaskOverviewItemResource::collection($tasks->filter(function ($task) {
                $verification = $task->borrowerTierTasks->first();
                return $verification && $verification->status === BorrowerTaskVerificationRequest::PROCESSING;
            })),
            'pending' => TaskOverviewItemResource::collection($tasks->filter(function ($task) {
                $verification = $task->borrowerTierTasks->first();
                return !$verification || $verification->status === BorrowerTaskVerificationRequest::PENDING;
            })),
            'rejected' => TaskOverviewItemResource::collection($tasks->filter(function ($task) {
                $verification = $task->borrowerTierTasks->first();
                return $verification && $verification->status === BorrowerTaskVerificationRequest::REJECTED;
            })),
        ];
    }
}
