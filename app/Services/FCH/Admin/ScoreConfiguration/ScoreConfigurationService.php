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
            'fico_above_minimum' => 'FICO Above Play Minimum',
            'recent_inquiries' => 'Recent Inquiries',
            'banking_relationship' => 'Banking Relationship',
            'revenue_income' => 'Revenue / Income',
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
            'fico_above_minimum' => 'Points above play minimum FICO score',
            'recent_inquiries' => 'Number of inquiries in recent history',
            'banking_relationship' => 'Strength of banking relationships',
            'revenue_income' => 'Annual revenue or documented income level',
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
            ->where('model_slug', 'funding_readiness')
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

        $dbProbTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_APPROVAL_PROBABILITY)
            ->orderBy('sort_order')
            ->get();

        $probFactorSlugs = ['fico_above_minimum', 'utilization', 'recent_inquiries', 'banking_relationship', 'tradeline_depth', 'revenue_income'];

        $probFactors = [];
        foreach ($probFactorSlugs as $i => $fSlug) {
            $subscoreTiers = [];
            $filteredTiers = $dbProbTiers->where('factor_slug', $fSlug);
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
            $probFactors[] = [
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

        $dbModifiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_LATE_PAYMENT_MODIFIER)
            ->where('model_slug', ScoreConfiguration::MODEL_APPROVAL_PROBABILITY)
            ->orderBy('sort_order')
            ->get();

        if ($dbModifiers->isEmpty()) {
            $defaultModifiers = [
                ['factor_slug' => 'late_30_1', 'label' => '1 x 30-day late', 'min_value' => 1, 'numeric_value' => -5, 'sort_order' => 1],
                ['factor_slug' => 'late_30_2_plus', 'label' => '2+ x 30-day lates', 'min_value' => 2, 'numeric_value' => -10, 'sort_order' => 2],
                ['factor_slug' => 'late_60_1', 'label' => '1 x 60-day late', 'min_value' => 1, 'numeric_value' => -15, 'sort_order' => 3],
                ['factor_slug' => 'late_60_2_plus', 'label' => '2+ x 60-day lates', 'min_value' => 2, 'numeric_value' => -25, 'sort_order' => 4],
                ['factor_slug' => 'late_90_1', 'label' => '1 x 90-day late', 'min_value' => 1, 'numeric_value' => -30, 'sort_order' => 5],
                ['factor_slug' => 'late_90_2_plus', 'label' => '2+ x 90-day lates', 'min_value' => 2, 'numeric_value' => -50, 'sort_order' => 6],
            ];
            $latePaymentModifiers = [];
            foreach ($defaultModifiers as $dm) {
                $latePaymentModifiers[] = [
                    'id' => null,
                    'category' => ScoreConfiguration::CATEGORY_LATE_PAYMENT_MODIFIER,
                    'factor_slug' => $dm['factor_slug'],
                    'label' => $dm['label'],
                    'min_value' => $dm['min_value'],
                    'numeric_value' => $dm['numeric_value'],
                    'sort_order' => $dm['sort_order'],
                ];
            }
        } else {
            $latePaymentModifiers = $dbModifiers->map(function ($mod) {
                return [
                    'id' => $mod->id,
                    'category' => $mod->category,
                    'factor_slug' => $mod->factor_slug,
                    'label' => $mod->label,
                    'min_value' => $mod->min_value,
                    'numeric_value' => $mod->numeric_value,
                    'sort_order' => $mod->sort_order,
                ];
            })->values()->all();
        }

        return [
            'models' => $models,
            'factors' => $factors,
            'readiness_bands' => $readinessBands,
            'late_payment_modifiers' => $latePaymentModifiers,
            'probability_factors' => $probFactors,
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

            if (isset($data['late_payment_modifiers'])) {
                $this->saveLatePaymentModifiers($data['late_payment_modifiers']);
            }

            if (isset($data['approval_probability'])) {
                $ap = $data['approval_probability'];
                foreach (['fico_above_minimum', 'utilization', 'recent_inquiries', 'banking_relationship', 'tradeline_depth', 'revenue_income'] as $slug) {
                    if (isset($ap[$slug])) {
                        $this->saveFactorWeightAndTiers(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY, $slug, $ap[$slug]);
                    }
                }
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
        if (isset($factorData['weight'])) {
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
        }

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

    private function saveLatePaymentModifiers(array $modifiers): void
    {
        foreach ($modifiers as $mod) {
            $slug = $mod['factor_slug'];
            ScoreConfiguration::updateOrCreate(
                [
                    'category' => ScoreConfiguration::CATEGORY_LATE_PAYMENT_MODIFIER,
                    'model_slug' => ScoreConfiguration::MODEL_APPROVAL_PROBABILITY,
                    'factor_slug' => $slug,
                ],
                [
                    'label' => $mod['label'] ?? null,
                    'min_value' => $mod['min_value'],
                    'numeric_value' => $mod['numeric_value'],
                    'sort_order' => $mod['sort_order'] ?? 0,
                ]
            );
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
