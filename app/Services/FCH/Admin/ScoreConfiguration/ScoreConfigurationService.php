<?php

namespace App\Services\FCH\Admin\ScoreConfiguration;

use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Facades\DB;

class ScoreConfigurationService
{
    private array $modelMeta = [
        'funding_readiness' => ['id' => 1, 'name' => 'Funding Readiness', 'description' => 'Overall business funding readiness score'],
        'card_approval_odds' => ['id' => 2, 'name' => 'Card Approval Odds', 'description' => 'Credit card approval probability score'],
    ];

    private array $factorMeta = [
        'fico' => ['id' => 1, 'name' => 'FICO Score', 'description' => 'Personal credit score'],
        'utilization' => ['id' => 2, 'name' => 'Credit Card Utilization', 'description' => 'Revolving credit utilization ratio'],
        'recent_inquiries' => ['id' => 3, 'name' => 'Recent Inquiries/New Accounts', 'description' => 'Recent credit inquiries and new accounts'],
        'time_in_business' => ['id' => 4, 'name' => 'Time In Business', 'description' => 'Length of time business has been operating'],
        'revenue_strength' => ['id' => 5, 'name' => 'Revenue Strength', 'description' => 'Business revenue and personal income strength'],
        'tradeline_depth' => ['id' => 6, 'name' => 'Tradeline Depth', 'description' => 'Credit account depth and limits'],
        'banking_relationships' => ['id' => 7, 'name' => 'Banking Relationships', 'description' => 'Banking relationship strength'],
    ];

    public function getAll(): array
    {
        $dbWeights = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)->get();

        $models = [];
        foreach ($this->modelMeta as $mSlug => $meta) {
            $mFactors = [];
            $filteredWeights = $dbWeights->where('model_slug', $mSlug)->sortBy('sort_order');
            foreach ($filteredWeights as $w) {
                $fSlug = $w->factor_slug;
                if (isset($this->factorMeta[$fSlug])) {
                    $mFactors[] = [
                        'id' => $this->factorMeta[$fSlug]['id'],
                        'slug' => $fSlug,
                        'name' => $this->factorMeta[$fSlug]['name'],
                        'description' => $this->factorMeta[$fSlug]['description'],
                        'pivot' => [
                            'weight' => $w->numeric_value,
                            'sort_order' => $w->sort_order,
                        ],
                    ];
                }
            }
            $models[] = [
                'id' => $meta['id'],
                'slug' => $mSlug,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'factors' => $mFactors,
            ];
        }

        $dbTiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->orderBy('sort_order')
            ->get();

        $factors = [];
        foreach ($this->factorMeta as $fSlug => $meta) {
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
                'id' => $meta['id'],
                'slug' => $fSlug,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'subscore_tiers' => $subscoreTiers,
            ];
        }

        $bandMappings = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_BAND_MAPPING)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($item) {
                $item->score_model_id = 2; // For backward compatibility with card approval odds model id
                return $item;
            });

        $readinessBands = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)
            ->orderBy('sort_order')
            ->get();

        $playEligibility = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_PLAY_ELIGIBILITY)
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
        $modelSlugs = [
            1 => 'funding_readiness',
            2 => 'card_approval_odds',
        ];
        $factorSlugs = [
            1 => 'fico',
            2 => 'utilization',
            3 => 'recent_inquiries',
            4 => 'time_in_business',
            5 => 'revenue_strength',
            6 => 'tradeline_depth',
            7 => 'banking_relationships',
        ];

        DB::transaction(function () use ($data, $modelSlugs, $factorSlugs) {
            // 1. Update weights using nested "models" or flat "model_factors"
            if (isset($data['models'])) {
                foreach ($data['models'] as $model) {
                    $modelSlug = $model['slug'] ?? ($modelSlugs[$model['id'] ?? null] ?? null);
                    if ($modelSlug && isset($model['factors'])) {
                        foreach ($model['factors'] as $factor) {
                            $factorSlug = $factor['slug'] ?? ($factorSlugs[$factor['id'] ?? null] ?? null);
                            if ($factorSlug) {
                                ScoreConfiguration::updateOrCreate(
                                    [
                                        'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
                                        'model_slug' => $modelSlug,
                                        'factor_slug' => $factorSlug,
                                    ],
                                    [
                                        'numeric_value' => $factor['weight'] ?? 0,
                                        'sort_order' => $factor['sort_order'] ?? 0,
                                    ]
                                );
                            }
                        }
                    }
                }
            } elseif (isset($data['model_factors'])) {
                foreach ($data['model_factors'] as $factor) {
                    $modelSlug = $modelSlugs[$factor['score_model_id']] ?? null;
                    $factorSlug = $factorSlugs[$factor['score_factor_id']] ?? null;
                    if ($modelSlug && $factorSlug) {
                        ScoreConfiguration::updateOrCreate(
                            [
                                'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
                                'model_slug' => $modelSlug,
                                'factor_slug' => $factorSlug,
                            ],
                            [
                                'numeric_value' => $factor['weight'] ?? 0,
                                'sort_order' => $factor['sort_order'] ?? 0,
                            ]
                        );
                    }
                }
            }

            // 2. Update subscore tiers using nested "factors" or flat "subscore_tiers"
            if (isset($data['factors'])) {
                foreach ($data['factors'] as $factor) {
                    if (isset($factor['tiers'])) {
                        foreach ($factor['tiers'] as $item) {
                            if (!empty($item['id'])) {
                                $updateData = [];
                                if (array_key_exists('min_value', $item)) {
                                    $updateData['min_value'] = $item['min_value'];
                                }
                                if (array_key_exists('max_value', $item)) {
                                    $updateData['max_value'] = $item['max_value'];
                                }
                                if (array_key_exists('numeric_value', $item)) {
                                    $updateData['numeric_value'] = $item['numeric_value'];
                                }
                                if (array_key_exists('conditions', $item)) {
                                    $updateData['conditions'] = $item['conditions'];
                                }
                                if (!empty($updateData)) {
                                    ScoreConfiguration::where('id', $item['id'])
                                        ->where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
                                        ->update($updateData);
                                }
                            }
                        }
                    }
                }
            } elseif (isset($data['subscore_tiers'])) {
                foreach ($data['subscore_tiers'] as $item) {
                    if (!empty($item['id'])) {
                        $updateData = [];
                        if (array_key_exists('min_value', $item)) {
                            $updateData['min_value'] = $item['min_value'];
                        }
                        if (array_key_exists('max_value', $item)) {
                            $updateData['max_value'] = $item['max_value'];
                        }
                        if (array_key_exists('numeric_value', $item)) {
                            $updateData['numeric_value'] = $item['numeric_value'];
                        }
                        if (array_key_exists('conditions', $item)) {
                            $updateData['conditions'] = $item['conditions'];
                        }
                        if (!empty($updateData)) {
                            ScoreConfiguration::where('id', $item['id'])
                                ->where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
                                ->update($updateData);
                        }
                    }
                }
            }

            if (isset($data['band_mappings'])) {
                foreach ($data['band_mappings'] as $item) {
                    if (!empty($item['id'])) {
                        $updateData = [];
                        if (array_key_exists('min_value', $item)) {
                            $updateData['min_value'] = $item['min_value'];
                        }
                        if (array_key_exists('max_value', $item)) {
                            $updateData['max_value'] = $item['max_value'];
                        }
                        if (array_key_exists('numeric_value', $item)) {
                            $updateData['numeric_value'] = $item['numeric_value'];
                        }
                        if (!empty($updateData)) {
                            ScoreConfiguration::where('id', $item['id'])
                                ->where('category', ScoreConfiguration::CATEGORY_BAND_MAPPING)
                                ->update($updateData);
                        }
                    }
                }
            }

            if (isset($data['readiness_bands'])) {
                foreach ($data['readiness_bands'] as $item) {
                    if (!empty($item['id'])) {
                        $updateData = [];
                        if (array_key_exists('min_value', $item)) {
                            $updateData['min_value'] = $item['min_value'];
                        }
                        if (array_key_exists('max_value', $item)) {
                            $updateData['max_value'] = $item['max_value'];
                        }
                        if (!empty($updateData)) {
                            ScoreConfiguration::where('id', $item['id'])
                                ->where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)
                                ->update($updateData);
                        }
                    }
                }
            }

            if (isset($data['play_eligibility'])) {
                foreach ($data['play_eligibility'] as $item) {
                    if (!empty($item['id'])) {
                        $updateData = [];
                        if (array_key_exists('min_value', $item)) {
                            $updateData['min_value'] = $item['min_value'];
                        }
                        if (array_key_exists('max_value', $item)) {
                            $updateData['max_value'] = $item['max_value'];
                        }
                        if (array_key_exists('numeric_value', $item)) {
                            $updateData['numeric_value'] = $item['numeric_value'];
                        }
                        if (!empty($updateData)) {
                            ScoreConfiguration::where('id', $item['id'])
                                ->where('category', ScoreConfiguration::CATEGORY_PLAY_ELIGIBILITY)
                                ->update($updateData);
                        }
                    }
                }
            }
        });
    }
}
