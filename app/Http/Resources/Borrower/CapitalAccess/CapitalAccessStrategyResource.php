<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Models\CreditBuilder\CapitalAccessApplication;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\Common\ScoreConfiguration;
use App\Services\Borrower\CapitalAccess\ApprovalOddsService;
use App\Services\Borrower\CapitalAccess\ApprovalProbabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalAccessStrategyResource extends JsonResource
{
    use HasEvaluatedChecklist;
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recommendedId = $this->additional['recommended_id'] ?? null;
        $borrower      = auth()->user()?->borrower;

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

        $timeSettingsFormatted = $timeConfigs->map($formatConfig)->values()->all();

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
                $probData = app(ApprovalProbabilityService::class)
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
            $oddsService = app(ApprovalOddsService::class);
            $sequences = $this->sequences;

            if ($borrower) {
                $playStatus = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                    ->where('capital_access_id', $this->id)
                    ->first();
                if ($playStatus && $playStatus->status === BorrowerCapitalAccessPlay::STATUS_COMPLETED) {
                    $appliedSequenceIds = CapitalAccessApplication::where('borrower_id', $borrower->id)
                        ->where('capital_access_id', $this->id)
                        ->pluck('capital_access_sequence_id')
                        ->all();
                    $sequences = $sequences->filter(fn($seq) => in_array($seq->id, $appliedSequenceIds));
                }
            }

            $roadmap = $sequences->map(function (CapitalAccessSequence $seq, int $index) use (
                $borrower, $ctx, $oddsTiers, $oddsService, $timeConfigs, $formatConfig
            ) {
                $seqStatus = $index === 0 ? 'Active' : 'Locked';

                $odds = 0;
                if ($borrower) {
                    $oddsData = $oddsService->calculate($borrower, $seq, $this->resource, $ctx, $oddsTiers);
                    $odds     = $oddsData['probability'] ?? 0;
                }

                $card = $seq->creditAccount;

                $accessTimeLower = $seq->access_time ? strtolower($seq->access_time) : null;
                $accessDayTimeLower = $seq->access_day_time ? strtolower($seq->access_day_time) : null;

                $accessTimeDetail = $accessTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessTimeLower) : null;
                $accessDayTimeDetail = $accessDayTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessDayTimeLower) : null;

                return [
                    'id'                            => $seq->credit_account_id,
                    'day'                           => $seq->sorting_order ?? ($index + 1),
                    'issuer'                        => $card?->issuer,
                    'card_name'                     => $card?->card_name,
                    'reference_url'                 => $card?->reference_url,
                    'exposure'                      => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
                    'status'                        => $seqStatus,
                    'why'                           => $seq->why_this_card,
                    'approval_odds'                 => $odds . '%',
                    'hard_pull'                     => $seq->hard_pull,
                    'access_time'                   => $seq->access_time,
                    'access_day'                    => $seq->access_day,
                    'access_day_time'               => $seq->access_day_time,
                    'access_time_detail'            => $formatConfig($accessTimeDetail),
                    'access_day_time_detail'        => $formatConfig($accessDayTimeDetail),
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
            'isCompleted'        => (bool) ($this->is_completed ?? false),
            'target_exposure'    => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline'       => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'approval_probability' => $probability . '%',
            'approval_status'    => $status,
            'reason_code'        => $reasonCode,
            'ReasonCode'         => $reasonCode,
            'unlock_at'          => $probData['unlock_at'] ?? null,
            'benefits'           => is_string($this->what_you_will_get)
                ? json_decode($this->what_you_will_get, true)
                : ($this->what_you_will_get ?? []),
            'checklist'          => $this->getEvaluatedChecklist($borrower, $this->pre_flight_checklist),
            'time_settings'      => $timeSettingsFormatted,
            'roadmap'            => $roadmap,
        ];
    }
}

