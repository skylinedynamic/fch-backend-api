<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\Common\ScoreConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStepDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * $this->resource is a CapitalAccessSequence instance.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seq      = $this->resource;
        $card     = $seq->creditAccount;
        $play     = $seq->capitalAccess;
        $borrower = auth()->user()?->borrower;

        /** @var \App\Services\Borrower\CapitalAccess\BorrowerContext|null $ctx */
        $ctx   = $this->additional['borrower_ctx'] ?? null;
        $cache = $this->additional['score_cache']  ?? null;

        $application = null;
        if ($borrower) {
            $application = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
                ->where('capital_access_sequence_id', $seq->id)
                ->first();
        }

        // Per-card approval odds
        $odds       = null;
        $oddsStatus = true;
        if ($borrower && !$application) {
            $oddsTiers  = $cache?->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_ODDS);
            $oddsData   = app(\App\Services\Borrower\CapitalAccess\ApprovalOddsService::class)
                ->calculate($borrower, $seq, $play, $ctx, $oddsTiers);
            $odds       = $oddsData['probability'] ?? 0;
            $oddsStatus = ($oddsData['status'] ?? true) !== false;
        }

        $application = null;
        if ($borrower) {
            $application = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
                ->where('capital_access_sequence_id', $seq->id)
                ->first();
        }

        return [
            'id'              => $seq->id,
            'application'     => $application ? [
                'id'           => $application->id,
                'credit_limit' => $application->credit_limit,
                'notes'        => $application->notes,
                'status'       => $application->status,
            ] : null,
            'sequence_order'  => $seq->sorting_order ?? 1,
            'credit_account_id' => $seq->credit_account_id,
            'issuer'          => $card?->issuer,
            'card_name'       => $card?->card_name,
            'exposure'        => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
            'est_limit_min'   => $seq->est_limit_min,
            'est_limit_max'   => $seq->est_limit_max,
            'hard_pull'       => $seq->hard_pull,
            'access_time'     => $seq->access_time,
            'access_day'      => $seq->access_day,
            'access_day_time' => $seq->access_day_time,
            'why_this_card'   => $seq->why_this_card,
            'requirements'    => is_string($seq->what_you_need)
                ? json_decode($seq->what_you_need, true)
                : ($seq->what_you_need ?? []),
            'approval_odds'   => $odds !== null ? $odds . '%' : null,
            'probability_status' => $oddsStatus,
            // Play context
            'play' => [
                'id'             => $play->id,
                'name'           => $play->name,
                'target_exposure' => currencyFormat($play->target_exposure_min) . ' - ' . currencyFormat($play->target_exposure_max),
            ],
        ];
    }
}
