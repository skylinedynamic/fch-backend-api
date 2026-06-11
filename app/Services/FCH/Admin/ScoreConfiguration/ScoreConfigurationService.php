<?php

namespace App\Services\FCH\Admin\ScoreConfiguration;

use App\Models\Common\ScoreConfigItem;
use App\Models\Common\ScoreFactor;
use App\Models\Common\ScoreModel;
use App\Models\Common\ScoreModelFactor;
use Illuminate\Support\Facades\DB;

class ScoreConfigurationService
{
    public function getAll(): array
    {
        $models = ScoreModel::with(['factors' => function ($q) {
            $q->orderByPivot('sort_order');
        }])->orderBy('sort_order')->get();

        $factors = ScoreFactor::with(['subscoreTiers' => function ($q) {
            $q->orderBy('sort_order');
        }])->orderBy('sort_order')->get();

        $bandMappings = ScoreConfigItem::where('category', ScoreConfigItem::CATEGORY_BAND_MAPPING)
            ->orderBy('sort_order')
            ->get();

        $readinessBands = ScoreConfigItem::where('category', ScoreConfigItem::CATEGORY_READINESS_BAND)
            ->orderBy('sort_order')
            ->get();

        $playEligibility = ScoreConfigItem::where('category', ScoreConfigItem::CATEGORY_PLAY_ELIGIBILITY)
            ->orderBy('sort_order')
            ->get();

        return [
            'models' => $models,
            'factors' => $factors,
            'band_mappings' => $bandMappings,
            'readiness_bands' => $readinessBands,
            'play_eligibility' => $playEligibility,
        ];
    }

    public function updateAll(array $data): void
    {
        DB::transaction(function () use ($data) {
            if (isset($data['model_factors'])) {
                $this->upsertModelFactors($data['model_factors']);
            }

            if (isset($data['subscore_tiers'])) {
                $this->upsertConfigItems(
                    $data['subscore_tiers'],
                    ScoreConfigItem::CATEGORY_SUBSCORE_TIER
                );
            }

            if (isset($data['band_mappings'])) {
                $this->upsertConfigItems(
                    $data['band_mappings'],
                    ScoreConfigItem::CATEGORY_BAND_MAPPING
                );
            }

            if (isset($data['readiness_bands'])) {
                $this->upsertConfigItems(
                    $data['readiness_bands'],
                    ScoreConfigItem::CATEGORY_READINESS_BAND
                );
            }

            if (isset($data['play_eligibility'])) {
                $this->upsertConfigItems(
                    $data['play_eligibility'],
                    ScoreConfigItem::CATEGORY_PLAY_ELIGIBILITY
                );
            }
        });
    }

    private function upsertModelFactors(array $factors): void
    {
        foreach ($factors as $factor) {
            ScoreModelFactor::upsert(
                [
                    'score_model_id' => $factor['score_model_id'],
                    'score_factor_id' => $factor['score_factor_id'],
                    'weight' => $factor['weight'] ?? 0,
                    'sort_order' => $factor['sort_order'] ?? 0,
                ],
                ['score_model_id', 'score_factor_id'],
                ['weight', 'sort_order']
            );
        }
    }

    private function upsertConfigItems(array $items, string $category): void
    {
        foreach ($items as $item) {
            $data = array_merge([
                'category' => $category,
                'min_value' => null,
                'max_value' => null,
                'numeric_value' => null,
                'conditions' => null,
                'sort_order' => 0,
            ], $item);

            unset($data['id'], $data['category']);

            if (!empty($item['id'])) {
                ScoreConfigItem::where('id', $item['id'])
                    ->where('category', $category)
                    ->update($data);
            }
        }
    }
}
