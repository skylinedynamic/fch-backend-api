<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Models\Common\ScoreConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyResource extends JsonResource
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

        /** @var \App\Services\Borrower\CapitalAccess\BorrowerContext|null $ctx */
        $ctx   = $this->additional['borrower_ctx'] ?? null;
        $cache = $this->additional['score_cache']  ?? null;

        // --- Approval probability -------------------------------------------
        $probability = 0;
        $status      = true;
        $reasonCode  = null;

        if ($borrower) {
            $probData = $this->resource->approval_probability_data ?? null;
            if (!$probData) {
                // Fallback: build on the fly (direct instantiation outside service)
                $probData = app(\App\Services\Borrower\CapitalAccess\ApprovalProbabilityService::class)
                    ->calculate($borrower, $this->resource, $ctx);
            }
            $probability = $probData['probability'];
            $status      = $probData['status'];
            $reasonCode  = $probData['reason_code'];
        }

        // --- Roadmap (sequences) -------------------------------------------
        $oddsTiers = null;
        if ($cache !== null) {
            $oddsTiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_ODDS);
        }

        $roadmap = [];
        if ($this->relationLoaded('sequences')) {
            $oddsService = app(\App\Services\Borrower\CapitalAccess\ApprovalOddsService::class);

            $roadmap = $this->sequences->map(function (CapitalAccessSequence $seq, int $index) use (
                $borrower, $ctx, $oddsTiers, $oddsService
            ) {
                $seqStatus = $index === 0 ? 'Active' : 'Locked';

                $odds = 0;
                if ($borrower) {
                    $oddsData = $oddsService->calculate($borrower, $seq, $this->resource, $ctx, $oddsTiers);
                    $odds     = $oddsData['probability'] ?? 0;
                }

                $card = $seq->creditAccount;

                return [
                    'id'                            => $seq->credit_account_id,
                    'day'                           => $seq->sorting_order ?? ($index + 1),
                    'issuer'                        => $card?->issuer,
                    'card_name'                     => $card?->card_name,
                    'exposure'                      => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
                    'status'                        => $seqStatus,
                    'why'                           => $seq->why_this_card,
                    'approval_odds'                 => $odds . '%',
                    'hard_pull'                     => $seq->hard_pull,
                    'access_time'                   => $seq->access_time,
                    'access_day'                    => $seq->access_day,
                    'access_day_time'               => $seq->access_day_time,
                    'minimum_fico_requirement'      => $seq->minimum_fico_requirement,
                    'maximum_utilization_threshold' => $seq->maximum_utilization_threshold,
                    'difficulty_modifier'           => $seq->difficulty_modifier,
                    'requirements'                  => is_string($seq->what_you_need)
                        ? json_decode($seq->what_you_need, true)
                        : ($seq->what_you_need ?? []),
                    'result'                        => null,
                ];
            })->all();
        }

        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'description'        => $this->sub_title,
            'isRecommended'      => $recommendedId === $this->id,
            'target_exposure'    => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline'       => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'approval_probability' => $probability . '%',
            'approval_status'    => $status,
            'reason_code'        => $reasonCode,
            'ReasonCode'         => $reasonCode,
            'benefits'           => is_string($this->what_you_will_get)
                ? json_decode($this->what_you_will_get, true)
                : ($this->what_you_will_get ?? []),
            'checklist'          => is_string($this->pre_flight_checklist)
                ? json_decode($this->pre_flight_checklist, true)
                : ($this->pre_flight_checklist ?? []),
            'roadmap'            => $roadmap,
        ];
    }
}
