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
        $requests = $this->resource['requests'];

        return [
            'tier' => TierResource::make($this->resource['tier']),
            'verified' => TaskOverviewItemResource::collection($requests->filter(function ($request) {
                return $request->status === BorrowerTaskVerificationRequest::COMPLETED;
            })),
            'processing' => TaskOverviewItemResource::collection($requests->filter(function ($request) {
                return $request->status === BorrowerTaskVerificationRequest::PROCESSING;
            })),
            'pending' => TaskOverviewItemResource::collection($requests->filter(function ($request) {
                return $request->status === BorrowerTaskVerificationRequest::PENDING;
            })),
            'rejected' => TaskOverviewItemResource::collection($requests->filter(function ($request) {
                return $request->status === BorrowerTaskVerificationRequest::REJECTED;
            })),
        ];
    }
}
