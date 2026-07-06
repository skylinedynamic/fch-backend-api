<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyChecklistResource extends JsonResource
{
    use HasEvaluatedChecklist;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $borrower = auth()->user()?->borrower;

        $probability = 0;
        $status      = true;
        $reasonCode  = null;

        if ($borrower) {
            $probData = $this->resource->approval_probability_data ?? null;
            if (!$probData) {
                $probData = app(\App\Services\Borrower\CapitalAccess\ApprovalProbabilityService::class)
                    ->calculate($borrower, $this->resource, $this->additional['borrower_ctx'] ?? null);
            }
            $probability = $probData['probability'];
            $status      = $probData['status'];
            $reasonCode  = $probData['reason_code'];
        }

        $recommendedId = $this->additional['recommended_id'] ?? null;
        $isRecommended = $recommendedId === $this->id;
        $isCompleted   = (bool) ($this->is_completed ?? false);

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'isRecommended'   => $isRecommended,
            'isCompleted'     => $isCompleted,
            'isAccessible'    => $isRecommended || $isCompleted,
            'approval_status' => $status,
            'reason_code'     => $reasonCode,
            'checklist'       => $this->getEvaluatedChecklist($borrower, $this->pre_flight_checklist),
        ];
    }
}
