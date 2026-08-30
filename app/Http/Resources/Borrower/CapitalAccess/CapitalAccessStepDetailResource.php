<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\Common\ScoreConfiguration;
use App\Models\CreditBuilder\CapitalAccessApplication;
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

        $timeConfigs = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_TIME)
            ->whereIn('model_slug', [ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::MODEL_GENERAL_SETTING])
            ->where('factor_slug', ScoreConfiguration::FACTOR_TIME_SETTING)
            ->get();

        $formatConfig = function ($config) {
            if (!$config) {
                return null;
            }
            $conditions = $config->conditions;
            return [
                'id'          => $config->id,
                'label'       => $config->label,
                'min_value'   => $config->min_value !== null ? (float) $config->min_value : null,
                'max_value'   => $config->max_value !== null ? (float) $config->max_value : null,
                'start'       => isset($conditions['start']) ? (float) $conditions['start'] : ($config->min_value !== null ? (float) $config->min_value : null),
                'end'         => isset($conditions['end']) ? (float) $conditions['end'] : ($config->max_value !== null ? (float) $config->max_value : null),
                'sort_order'  => $config->sort_order,
            ];
        };

        /** @var \App\Services\Borrower\CapitalAccess\BorrowerContext|null $ctx */
        $ctx   = $this->additional['borrower_ctx'] ?? null;
        $cache = $this->additional['score_cache']  ?? null;

        $application = null;
        if ($borrower) {
            $application = CapitalAccessApplication::where('borrower_id', $borrower->id)
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

        $accessTimeLower = $seq->access_time ? strtolower($seq->access_time) : null;
        $accessDayTimeLower = $seq->access_day_time ? strtolower($seq->access_day_time) : null;

        $accessTimeDetail = $accessTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessTimeLower) : null;
        $accessDayTimeDetail = $accessDayTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessDayTimeLower) : null;

        $recommendedId = $this->additional['recommended_id'] ?? null;
        $isCompleted = (bool) ($this->additional['is_completed'] ?? false);
        $isAccessible = false;
        if ($borrower) {
            $runningPlay = \App\Services\Borrower\CapitalAccess\PlayAccessGuard::getRunningPlay($borrower->id);
            if ($runningPlay) {
                $isAccessible = ($play?->id === $runningPlay->capital_access_id);
            } else {
                $isDelayActive = \App\Services\Borrower\CapitalAccess\PlayAccessGuard::isDelayActive($borrower->id);
                $isAccessible  = $isDelayActive ? $isCompleted : true;
            }
        } else {
            $isAccessible = ($recommendedId === $play?->id) || $isCompleted;
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
            'image_url'       => $card?->image_url,
            'full_image_url'  => $card?->full_image_url,
            'reference_url'   => $card?->reference_url,
            'exposure'        => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
            'est_limit_min'   => $seq->est_limit_min,
            'est_limit_max'   => $seq->est_limit_max,
            'hard_pull'       => $seq->hard_pull,
            'access_time'     => $seq->access_time,
            'access_day'      => $seq->access_day,
            'access_day_time' => $seq->access_day_time,
            'access_time_detail' => $formatConfig($accessTimeDetail),
            'access_day_time_detail' => $formatConfig($accessDayTimeDetail),
            'why_this_card'   => $seq->why_this_card,
            'requirements'    => is_string($seq->what_you_need)
                ? json_decode($seq->what_you_need, true)
                : ($seq->what_you_need ?? []),
            'approval_odds'   => $odds !== null ? $odds . '%' : null,
            'probability_status' => $oddsStatus,
            // Play context
            'play' => [
                'id'              => $play->id,
                'name'            => $play->name,
                'target_exposure' => currencyFormat($play->target_exposure_min) . ' - ' . currencyFormat($play->target_exposure_max),
                'isRecommended'   => ($recommendedId ?? null) === $play->id,
                'isCompleted'     => $isCompleted,
                'isAccessible'    => $isAccessible,
                'reason_code'     => $this->additional['reason_code'] ?? null,
                'unlock_at'       => $this->additional['unlock_at'] ?? null,
            ],
        ];
    }
}
