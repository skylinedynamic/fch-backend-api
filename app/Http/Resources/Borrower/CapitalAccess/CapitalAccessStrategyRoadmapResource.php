<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Models\Common\ScoreConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyRoadmapResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $borrower = auth()->user()?->borrower;

        /** @var \App\Services\Borrower\CapitalAccess\BorrowerContext|null $ctx */
        $ctx   = $this->additional['borrower_ctx'] ?? null;
        $cache = $this->additional['score_cache']  ?? null;

        // --- Approval odds per card ----------------------------------------
        $oddsTiers = null;
        if ($cache !== null) {
            $oddsTiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_ODDS);
        }

        $oddsService = app(\App\Services\Borrower\CapitalAccess\ApprovalOddsService::class);

        $applications = collect();
        if ($borrower) {
            $applications = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
                ->where('capital_access_id', $this->id)
                ->get()
                ->keyBy('capital_access_sequence_id');
        }

        // --- Build and sort sequences by access schedule -------------------
        $sequences = $this->relationLoaded('sequences')
            ? $this->sequences
            : collect();

        /**
         * Sort order:
         *   access_time = 'Morning'  → group 0
         *   access_time = 'Evening'  → group 1
         *   access_time = 'Anytime'  → group 2
         *   access_time = 'Day'      → group 3, sub-sorted by access_day ASC,
         *                              then access_day_time: Morning→0, Evening→1, Anytime→2
         */
        $timeOrder     = ['Morning' => 0, 'Evening' => 1, 'Anytime' => 2, 'Day' => 3];
        $dayTimeOrder  = ['Morning' => 0, 'Evening' => 1, 'Anytime' => 2];

        $sequences = $sequences->sortBy(function (CapitalAccessSequence $seq) use ($timeOrder, $dayTimeOrder) {
            $group   = $timeOrder[$seq->access_time] ?? 99;
            $day     = $seq->access_day ?? 0;
            $subTime = $dayTimeOrder[$seq->access_day_time] ?? 99;
            return [$group, $day, $subTime];
        })->values();

        // --- Map sequences into roadmap steps ------------------------------
        $roadmap = $sequences->map(function (CapitalAccessSequence $seq, int $index) use (
            $borrower, $ctx, $oddsTiers, $oddsService, $applications
        ) {
            $card = $seq->creditAccount;

            // Per-card odds
            $odds       = 0;
            $oddsStatus = true;
            if ($borrower) {
                $oddsData   = $oddsService->calculate($borrower, $seq, $this->resource, $ctx, $oddsTiers);
                $odds       = $oddsData['probability'] ?? 0;
                $oddsStatus = ($oddsData['status'] ?? true) !== false;
            }

            $app = $applications->get($seq->id);

            return [
                'id'              => $seq->id,
                'sequence_order'  => $index + 1,
                'credit_account_id' => $seq->credit_account_id,
                'issuer'          => $card?->issuer,
                'card_name'       => $card?->card_name,
                'exposure'        => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
                'hard_pull'       => $seq->hard_pull,
                'access_time'     => $seq->access_time,
                'access_day'      => $seq->access_day,
                'access_day_time' => $seq->access_day_time,
                'why_this_card'   => $seq->why_this_card,
                'requirements'    => is_string($seq->what_you_need)
                    ? json_decode($seq->what_you_need, true)
                    : ($seq->what_you_need ?? []),
                'approval_odds'   => $odds . '%',
                'probability_status' => $oddsStatus,   // false → card is locked
                'application_status' => $app ? $app->status : null,
                'application'     => $app ? [
                    'id'           => $app->id,
                    'credit_limit' => $app->credit_limit,
                    'notes'        => $app->notes,
                    'status'       => $app->status,
                ] : null,
            ];
        })->all();

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'target_exposure' => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline'    => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'roadmap'         => $roadmap,
        ];
    }
}
