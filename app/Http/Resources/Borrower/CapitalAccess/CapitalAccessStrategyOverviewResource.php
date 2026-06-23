<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyOverviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recommendedId = $this->additional['recommended_id'] ?? null;
        $borrower      = auth()->user()?->borrower;

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

        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'description'          => $this->sub_title,
            'isRecommended'        => $recommendedId === $this->id,
            'target_exposure'      => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline'         => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'approval_probability' => $probability . '%',
            'approval_status'      => $status,
            'reason_code'          => $reasonCode,
            'ReasonCode'           => $reasonCode,
            'benefits'             => is_string($this->what_you_will_get)
                ? json_decode($this->what_you_will_get, true)
                : ($this->what_you_will_get ?? []),
        ];
    }
}
