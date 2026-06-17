<?php

namespace App\Services\FCH\Admin\ScoreConfiguration;

use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Facades\DB;

class ScoreConfigurationService
{
    private function factorLabel(string $slug): string
    {
        return match ($slug) {
            'fico' => 'FICO Score',
            'utilization' => 'Credit Card Utilization',
            'time_in_business' => 'Time In Business',
            'revenue_strength' => 'Revenue Strength',
            'tradeline_depth' => 'Tradeline Depth',
            'banking_relationships' => 'Banking Relationships',
            default => ucwords(str_replace('_', ' ', $slug)),
        };
    }

    private function factorDescription(string $slug): string
    {
        return match ($slug) {
            'fico' => 'Personal credit score',
            'utilization' => 'Revolving credit utilization ratio',
            'time_in_business' => 'Length of time business has been operating',
            'revenue_strength' => 'Business revenue and personal income strength',
            'tradeline_depth' => 'Credit account depth and limits',
            'banking_relationships' => 'Banking relationship strength',
            default => '',
        };
    }

    public function getAll(): array
    {
        $dbWeights = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->orderBy('sort_order')
            ->get();

        $mFactors = [];
        foreach ($dbWeights as $i => $w) {
            $fSlug = $w->factor_slug;
            $mFactors[] = [
                'id' => $i + 1,
                'slug' => $fSlug,
                'name' => $this->factorLabel($fSlug),
                'description' => $this->factorDescription($fSlug),
                'pivot' => [
                    'weight' => $w->numeric_value,
                    'sort_order' => $w->sort_order,
                ],
            ];
        }

        $models = [];
        $models[] = [
            'id' => 1,
            'slug' => 'funding_readiness',
            'name' => 'Funding Readiness',
            'description' => 'Overall business funding readiness score',
            'factors' => $mFactors,
        ];

        $dbTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->orderBy('sort_order')
            ->get();

        $factorSlugs = $dbWeights->pluck('factor_slug')->unique()->values()->all();

        $factors = [];
        foreach ($factorSlugs as $i => $fSlug) {
            $subscoreTiers = [];
            $filteredTiers = $dbTiers->where('factor_slug', $fSlug);
            foreach ($filteredTiers as $tier) {
                $subscoreTiers[] = [
                    'id' => $tier->id,
                    'factor_slug' => $fSlug,
                    'category' => $tier->category,
                    'label' => $tier->label,
                    'min_value' => $tier->min_value,
                    'max_value' => $tier->max_value,
                    'numeric_value' => $tier->numeric_value,
                    'conditions' => $tier->conditions,
                    'sort_order' => $tier->sort_order,
                ];
            }
            $factors[] = [
                'id' => $i + 1,
                'slug' => $fSlug,
                'name' => $this->factorLabel($fSlug),
                'description' => $this->factorDescription($fSlug),
                'subscore_tiers' => $subscoreTiers,
            ];
        }

        $readinessBands = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)
            ->where('model_slug', 'funding_readiness')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($band) {
                return [
                    'id' => $band->id,
                    'category' => $band->category,
                    'label' => $band->label,
                    'color' => $band->color,
                    'min_value' => $band->min_value,
                    'max_value' => $band->max_value,
                    'sort_order' => $band->sort_order,
                ];
            })->values()->all();

        return [
            'models' => $models,
            'factors' => $factors,
            'readiness_bands' => $readinessBands,
        ];
    }

    public function updateAll(array $data): void
    {
        DB::transaction(function () use ($data) {
            if (isset($data['funding_readiness'])) {
                $fr = $data['funding_readiness'];
                $this->saveCoreCreditStrength($fr);
                $this->saveFinancialCapacity($fr);
                $this->saveBusinessMaturityBanking($fr);
                $this->saveBureauDepth($fr);
            }

            if (isset($data['readiness_bands'])) {
                $this->saveReadinessBands($data['readiness_bands']);
            }
        });
    }

    private function saveCoreCreditStrength(array $fundingReadiness): void
    {
        foreach (['fico', 'utilization'] as $slug) {
            if (isset($fundingReadiness[$slug])) {
                $this->saveFactorWeightAndTiers('funding_readiness', $slug, $fundingReadiness[$slug]);
            }
        }
    }

    private function saveFinancialCapacity(array $fundingReadiness): void
    {
        if (isset($fundingReadiness['revenue_strength'])) {
            $this->saveFactorWeightAndTiers('funding_readiness', 'revenue_strength', $fundingReadiness['revenue_strength']);
        }
    }

    private function saveBusinessMaturityBanking(array $fundingReadiness): void
    {
        foreach (['time_in_business', 'banking_relationships'] as $slug) {
            if (isset($fundingReadiness[$slug])) {
                $this->saveFactorWeightAndTiers('funding_readiness', $slug, $fundingReadiness[$slug]);
            }
        }
    }

    private function saveBureauDepth(array $fundingReadiness): void
    {
        if (isset($fundingReadiness['tradeline_depth'])) {
            $this->saveFactorWeightAndTiers('funding_readiness', 'tradeline_depth', $fundingReadiness['tradeline_depth']);
        }
    }

    private function saveReadinessBands(array $bands): void
    {
        foreach ($bands as $band) {
            $sortOrder = $band['sort_order'] ?? 0;

            $updateData = [];
            if (array_key_exists('label', $band)) {
                $updateData['label'] = $band['label'];
            }
            if (array_key_exists('color', $band)) {
                $updateData['color'] = $band['color'];
            }
            if (array_key_exists('min_value', $band)) {
                $updateData['min_value'] = $band['min_value'];
            }
            if (array_key_exists('max_value', $band)) {
                $updateData['max_value'] = $band['max_value'];
            }

            if (!empty($updateData)) {
                ScoreConfiguration::updateOrCreate(
                    [
                        'category' => ScoreConfiguration::CATEGORY_READINESS_BAND,
                        'model_slug' => 'funding_readiness',
                        'sort_order' => $sortOrder,
                    ],
                    $updateData
                );
            }
        }
    }

    private function saveFactorWeightAndTiers(string $modelSlug, string $factorSlug, array $factorData): void
    {
        ScoreConfiguration::updateOrCreate(
            [
                'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
                'model_slug' => $modelSlug,
                'factor_slug' => $factorSlug,
            ],
            [
                'numeric_value' => $factorData['weight'] ?? 0,
                'sort_order' => $factorData['order_sort'] ?? 0,
            ]
        );

        if (!isset($factorData['factors'])) {
            return;
        }

        foreach ($factorData['factors'] as $tier) {
            $sortOrder = $tier['sort_order'] ?? 0;

            $existing = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
                ->where('model_slug', $modelSlug)
                ->where('factor_slug', $factorSlug)
                ->where('sort_order', $sortOrder)
                ->first();

            if (!$existing) {
                continue;
            }

            $updateData = [];
            if (array_key_exists('min_value', $tier)) {
                $updateData['min_value'] = $tier['min_value'];
            }
            if (array_key_exists('max_value', $tier)) {
                $updateData['max_value'] = $tier['max_value'];
            }
            if (array_key_exists('sub_score', $tier)) {
                $updateData['numeric_value'] = $tier['sub_score'];
            }
            if (isset($tier['condition'])) {
                $updateData['conditions'] = $this->convertConditionFormat($tier['condition']);
            }
            if (array_key_exists('label', $tier)) {
                $updateData['label'] = $tier['label'];
            }

            if (!empty($updateData)) {
                ScoreConfiguration::where('id', $existing->id)->update($updateData);
            }
        }
    }

    private function convertConditionFormat(array $condition): array
    {
        $conditions = [];

        foreach ($condition as $field => $rule) {
            if ($field === 'each') {
                continue;
            }

            $fieldName = $field === 'month_check' ? 'checking_months' : $field;

            $conditions[] = [
                $fieldName => [
                    'operator' => $rule['operator'],
                    'value' => $rule['value'],
                ],
            ];
        }

        if (isset($condition['each'])) {
            $conditions[] = ['each' => $condition['each']];
        }

        return $conditions;
    }
}
