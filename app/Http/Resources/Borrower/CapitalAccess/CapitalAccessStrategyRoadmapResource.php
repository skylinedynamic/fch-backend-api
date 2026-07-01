<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccess;
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
        $timeOrder     = [
            CapitalAccess::ACCESS_TIME_MORNING => 0,
            CapitalAccess::ACCESS_TIME_EVENING => 1,
            CapitalAccess::ACCESS_TIME_ANYTIME => 2,
            CapitalAccess::ACCESS_TIME_DAY     => 3,
        ];
        $dayTimeOrder  = [
            CapitalAccess::ACCESS_TIME_MORNING => 0,
            CapitalAccess::ACCESS_TIME_EVENING => 1,
            CapitalAccess::ACCESS_TIME_ANYTIME => 2,
        ];

        $sequences = $sequences->sortBy(function (CapitalAccessSequence $seq) use ($timeOrder, $dayTimeOrder) {
            $group   = $timeOrder[$seq->access_time] ?? 99;
            $day     = $seq->access_day ?? 0;
            $subTime = $dayTimeOrder[$seq->access_day_time] ?? 99;
            return [$group, $day, $subTime];
        })->values();

        // --- Map sequences into roadmap steps ------------------------------
        $roadmap = $sequences->map(function (CapitalAccessSequence $seq, int $index) use (
            $borrower, $ctx, $oddsTiers, $oddsService, $applications, $timeConfigs, $formatConfig
        ) {
            $card = $seq->creditAccount;

            $app = $applications->get($seq->id);

            $reasonCode = null;
            $odds = null;
            $oddsStatus = true;
            if ($borrower) {
                if (!$app) {
                    $oddsData   = $oddsService->calculate($borrower, $seq, $this->resource, $ctx, $oddsTiers);
                    $odds       = $oddsData['probability'] ?? 0;
                    $oddsStatus = ($oddsData['status'] ?? true) !== false;
                    $reasonCode = $oddsData['reason_code'] ?? null;
                }
            }

            $accessTimeLower = $seq->access_time ? strtolower($seq->access_time) : null;
            $accessDayTimeLower = $seq->access_day_time ? strtolower($seq->access_day_time) : null;

            $accessTimeDetail = $accessTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessTimeLower) : null;
            $accessDayTimeDetail = $accessDayTimeLower ? $timeConfigs->first(fn ($c) => strtolower($c->label) === $accessDayTimeLower) : null;

            return [
                'id'              => $seq->id,
                'sequence_order'  => $index + 1,
                'credit_account_id' => $seq->credit_account_id,
                'issuer'          => $card?->issuer,
                'card_name'       => $card?->card_name,
                'reference_url'   => $card?->reference_url,
                'exposure'        => currencyFormat($seq->est_limit_min ?? 0) . ' - ' . currencyFormat($seq->est_limit_max ?? 0),
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
                'probability_status' => $oddsStatus,   // false → card is locked
                'reason_code'     => $reasonCode,
                'minimum_fico_requirement' => $seq->minimum_fico_requirement,
                'maximum_utilization_threshold' => $seq->maximum_utilization_threshold,
                'application_status' => $app ? $app->status : null,
                'application'     => $app ? [
                    'id'           => $app->id,
                    'credit_limit' => $app->credit_limit,
                    'notes'        => $app->notes,
                    'status'       => $app->status,
                ] : null,
            ];
        })->all();

        $firstSubmittedAt = null;
        if ($borrower) {
            $playStatus = \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                ->where('capital_access_id', $this->id)
                ->first();
            if ($playStatus) {
                $firstSubmittedAt = $playStatus->created_at?->toIso8601String();
            }
        }
        if (!$firstSubmittedAt && $applications->isNotEmpty()) {
            $earliestApp = $applications->sortBy('created_at')->first();
            if ($earliestApp) {
                $firstSubmittedAt = $earliestApp->created_at?->toIso8601String();
            }
        }

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'target_exposure' => currencyFormat($this->target_exposure_min) . ' - ' . currencyFormat($this->target_exposure_max),
            'est_timeline'    => $this->est_timeline_min . ' - ' . $this->est_timeline_max . ' days',
            'time_settings'   => $timeSettingsFormatted,
            'first_submitted_at' => $firstSubmittedAt,
            'roadmap'         => $roadmap,
        ];
    }
}
