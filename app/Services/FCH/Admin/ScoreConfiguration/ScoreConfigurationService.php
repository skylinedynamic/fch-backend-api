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
            'business_revenue_strength' => 'Business Revenue Strength',
            'personal_revenue_strength' => 'Personal Revenue Strength',
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
            'business_revenue_strength' => 'Business monthly revenue',
            'personal_revenue_strength' => 'Personal annual income',
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
            ->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)
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
            'slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'name' => 'Funding Readiness',
            'description' => 'Overall business funding readiness score',
            'factors' => $mFactors,
        ];

        $dbTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)
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

        $latePaymentModifiers = [];
        if (!$dbModifiers->isEmpty()) {
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

        $dbOddsTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_APPROVAL_ODDS)
            ->orderBy('sort_order')
            ->get();

        $oddsFactors = [];
        foreach ($probFactorSlugs as $i => $fSlug) {
            $subscoreTiers = [];
            $filteredTiers = $dbOddsTiers->where('factor_slug', $fSlug);
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
            $oddsFactors[] = [
                'id' => $i + 1,
                'slug' => $fSlug,
                'name' => $this->factorLabel($fSlug),
                'description' => $this->factorDescription($fSlug),
                'subscore_tiers' => $subscoreTiers,
            ];
        }

        $timeSettings = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_TIME)
            ->where('model_slug', ScoreConfiguration::MODEL_GENERAL_SETTING)
            ->where('factor_slug', ScoreConfiguration::FACTOR_TIME_SETTING)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($setting) {
                $conditions = $setting->conditions;
                return [
                    'id' => $setting->id,
                    'label' => $setting->label,
                    'start' => isset($conditions['start']) ? (float) $conditions['start'] : ($setting->min_value !== null ? (float) $setting->min_value : null),
                    'end' => isset($conditions['end']) ? (float) $conditions['end'] : ($setting->max_value !== null ? (float) $setting->max_value : null),
                    'sort_order' => $setting->sort_order,
                ];
            })->values()->all();

        $nextPlayDelay = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_TIME)
            ->where('model_slug', ScoreConfiguration::MODEL_GENERAL_SETTING)
            ->where('factor_slug', ScoreConfiguration::FACTOR_NEXT_PLAY_DELAY)
            ->first();

        $nextPlayDelayValue = $nextPlayDelay ? (int) $nextPlayDelay->numeric_value : 0;

        $startupTier = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)
            ->where('factor_slug', ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH)
            ->first();

        if (!$startupTier) {
            $startupRevenueStrength = [
                'factor_slug' => ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH,
                'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
                'label' => 'Startup Exception',
                'min_value' => null,
                'max_value' => null,
                'numeric_value' => 55.0,
                'conditions' => [
                    ['business_revenue' => ['operator' => '<=', 'value' => 0]],
                    ['business_age' => ['operator' => '<=', 'value' => 24]],
                ],
                'sort_order' => 1,
            ];
        } else {
            $startupRevenueStrength = [
                'id' => $startupTier->id,
                'factor_slug' => ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH,
                'category' => $startupTier->category,
                'label' => $startupTier->label,
                'min_value' => $startupTier->min_value,
                'max_value' => $startupTier->max_value,
                'numeric_value' => $startupTier->numeric_value,
                'conditions' => $startupTier->conditions,
                'sort_order' => $startupTier->sort_order,
            ];
        }

        return [
            'models' => $models,
            'factors' => $factors,
            'readiness_bands' => $readinessBands,
            'late_payment_modifiers' => $latePaymentModifiers,
            'probability_factors' => $probFactors,
            'odds_factors' => $oddsFactors,
            'time_settings' => $timeSettings,
            'next_play_delay' => $nextPlayDelayValue,
            'startup_revenue_strength' => $startupRevenueStrength,
        ];
    }

    public function updateAll(array $data): void
    {
        DB::transaction(function () use ($data) {
            if (isset($data['funding_readiness'])) {
                $fr = $data['funding_readiness'];
                $frFactors = $fr['factors'] ?? [];
                $this->saveCoreCreditStrength($frFactors);
                $this->saveFinancialCapacity($frFactors);
                $this->saveBusinessMaturityBanking($frFactors);
                $this->saveBureauDepth($frFactors);
                $this->saveStartupRevenueStrength($frFactors);

                if (isset($fr['readiness_bands'])) {
                    $this->saveReadinessBands($fr['readiness_bands']);
                }
            }

            if (isset($data['approval_probability'])) {
                $ap = $data['approval_probability'];
                foreach (['fico_above_minimum', 'utilization', 'recent_inquiries', 'banking_relationship', 'tradeline_depth', 'revenue_income'] as $slug) {
                    if (isset($ap[$slug])) {
                        $this->saveFactorWeightAndTiers(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY, $slug, $ap[$slug]);
                    }
                }

                if (isset($ap['late_payment_modifiers'])) {
                    $this->saveLatePaymentModifiers($ap['late_payment_modifiers']);
                }
            }

            if (isset($data['approval_odds'])) {
                $ao = $data['approval_odds'];
                foreach (['fico_above_minimum', 'utilization', 'recent_inquiries', 'banking_relationship', 'tradeline_depth', 'revenue_income'] as $slug) {
                    if (isset($ao[$slug])) {
                        $this->saveFactorWeightAndTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, $slug, $ao[$slug]);
                    }
                }
            }

            if (isset($data['general_setting'])) {
                $gs = $data['general_setting'];
                if (isset($gs['time_settings'])) {
                    $this->saveTimeSettings($gs['time_settings']);
                }
                if (isset($gs['next_play_delay'])) {
                    $this->saveNextPlayDelay((int) $gs['next_play_delay']);
                }
            }
        });
    }

    private function saveNextPlayDelay(int $days): void
    {
        ScoreConfiguration::updateOrCreate(
            [
                'category' => ScoreConfiguration::CATEGORY_TIME,
                'model_slug' => ScoreConfiguration::MODEL_GENERAL_SETTING,
                'factor_slug' => ScoreConfiguration::FACTOR_NEXT_PLAY_DELAY,
            ],
            [
                'label' => 'Next Play Delay',
                'numeric_value' => $days,
            ]
        );
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
        if (!isset($fundingReadiness['revenue_strength'])) {
            return;
        }

        $rs = $fundingReadiness['revenue_strength'];

        // Save weight and order_sort
        if (isset($rs['weight'])) {
            ScoreConfiguration::updateOrCreate(
                [
                    'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
                    'model_slug' => 'funding_readiness',
                    'factor_slug' => 'revenue_strength',
                ],
                [
                    'numeric_value' => $rs['weight'],
                    'sort_order' => $rs['order_sort'] ?? 0,
                ]
            );
        }

        if (isset($rs['factors']) && is_array($rs['factors'])) {
            $this->saveFactorWeightAndTiers('funding_readiness', 'revenue_strength', [
                'factors' => $rs['factors'],
            ]);
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

    private function saveStartupRevenueStrength(array $fundingReadiness): void
    {
        if (!isset($fundingReadiness['startup_revenue_strength'])) {
            return;
        }

        $srs = $fundingReadiness['startup_revenue_strength'];
        if (isset($srs['factors']) && is_array($srs['factors'])) {
            foreach ($srs['factors'] as $tier) {
                $sortOrder = $tier['sort_order'] ?? 1;

                $updateData = [
                    'label' => $tier['label'] ?? ScoreConfiguration::STATUS_STARTUP_EXCEPTION,
                    'numeric_value' => $tier['sub_score'] ?? 55,
                    'conditions' => isset($tier['condition']) ? $this->convertConditionFormat($tier['condition']) : [],
                ];

                ScoreConfiguration::updateOrCreate(
                    [
                        'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
                        'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
                        'factor_slug' => ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH,
                        'sort_order' => $sortOrder,
                    ],
                    $updateData
                );
            }
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
                        'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
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

    private function saveTimeSettings(array $timeSettings): void
    {
        foreach ($timeSettings as $setting) {
            $sortOrder = $setting['sort_order'] ?? 0;
            ScoreConfiguration::updateOrCreate(
                [
                    'category' => ScoreConfiguration::CATEGORY_TIME,
                    'model_slug' => ScoreConfiguration::MODEL_GENERAL_SETTING,
                    'factor_slug' => ScoreConfiguration::FACTOR_TIME_SETTING,
                    'sort_order' => $sortOrder,
                ],
                [
                    'label' => $setting['label'] ?? null,
                    'min_value' => isset($setting['start']) ? (float) $setting['start'] : null,
                    'max_value' => isset($setting['end']) ? (float) $setting['end'] : null,
                    'conditions' => [
                        'start' => isset($setting['start']) ? (float) $setting['start'] : null,
                        'end' => isset($setting['end']) ? (float) $setting['end'] : null,
                    ],
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
