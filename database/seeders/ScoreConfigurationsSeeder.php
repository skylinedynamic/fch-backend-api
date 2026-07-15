<?php

namespace Database\Seeders;

use App\Models\Common\ScoreConfiguration;
use Illuminate\Database\Seeder;

class ScoreConfigurationsSeeder extends Seeder
{
    public function run(): void
    {
        ScoreConfiguration::truncate();

        $this->seedModelWeights();
        $this->seedSubscoreTiers();
        $this->seedReadinessBands();
        $this->seedApprovalProbabilityTiers();
        $this->seedApprovalOddsTiers();
        $this->seedTimeSettings();
    }

    private function seedModelWeights(): void
    {
        $rows = [
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_FICO, 'numeric_value' => 35, 'sort_order' => 1],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_UTILIZATION, 'numeric_value' => 25, 'sort_order' => 2],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_TIME_IN_BUSINESS, 'numeric_value' => 15, 'sort_order' => 3],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_REVENUE_STRENGTH, 'numeric_value' => 10, 'sort_order' => 4],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_TRADELINE_DEPTH, 'numeric_value' => 10, 'sort_order' => 5],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS, 'factor_slug' => ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS, 'numeric_value' => 5, 'sort_order' => 6],
        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create($row);
        }
    }

    private function seedSubscoreTiers(): void
    {
        $items = collect();

        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_FICO, [
            ['min' => 780, 'subscore' => 100, 'order' => 1],
            ['min' => 760, 'max' => 779, 'subscore' => 95, 'order' => 2],
            ['min' => 740, 'max' => 759, 'subscore' => 90, 'order' => 3],
            ['min' => 720, 'max' => 739, 'subscore' => 80, 'order' => 4],
            ['min' => 700, 'max' => 719, 'subscore' => 70, 'order' => 5],
            ['min' => 680, 'max' => 699, 'subscore' => 55, 'order' => 6],
            ['min' => 660, 'max' => 679, 'subscore' => 40, 'order' => 7],
            ['max' => 659, 'subscore' => 20, 'order' => 8],
        ]));

        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_UTILIZATION, [
            ['max' => 5, 'subscore' => 100, 'order' => 1],
            ['min' => 5.01, 'max' => 10, 'subscore' => 90, 'order' => 2],
            ['min' => 10.01, 'max' => 20, 'subscore' => 75, 'order' => 3],
            ['min' => 20.01, 'max' => 30, 'subscore' => 60, 'order' => 4],
            ['min' => 30.01, 'max' => 40, 'subscore' => 35, 'order' => 5],
            ['min' => 40.01, 'subscore' => 10, 'order' => 6],
        ]));

        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_TIME_IN_BUSINESS, [
            ['label' => ScoreConfiguration::STATUS_TIME_ESTABLISHED, 'min' => 36, 'subscore' => 100, 'order' => 1],
            ['label' => ScoreConfiguration::STATUS_TIME_MATURE, 'min' => 24, 'max' => 35, 'subscore' => 85, 'order' => 2],
            ['label' => ScoreConfiguration::STATUS_TIME_YOUNG, 'min' => 12, 'max' => 23, 'subscore' => 70, 'order' => 3],
            ['label' => ScoreConfiguration::STATUS_TIME_EARLY_STAGE, 'min' => 6, 'max' => 11, 'subscore' => 50, 'order' => 4],
            ['label' => ScoreConfiguration::STATUS_TIME_STARTUP, 'max' => 5, 'subscore' => 25, 'order' => 5],
        ]));

        // Financial Capacity
        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_REVENUE_STRENGTH, [
            [
                'label' => ScoreConfiguration::STATUS_REVENUE_STRONG,
                'subscore' => 100,
                'order' => 1,
                'conditions' => [
                    ['business_revenue' => ['operator' => '>=', 'value' => 500000]],
                    ['personal_income' => ['operator' => '>=', 'value' => 150000]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_REVENUE_MODERATE,
                'subscore' => 75,
                'order' => 2,
                'conditions' => [
                    ['business_revenue' => ['operator' => '>=', 'value' => 150000]],
                    ['personal_income' => ['operator' => '>=', 'value' => 50000]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_REVENUE_LIMITED,
                'subscore' => 55,
                'order' => 3,
                'conditions' => [
                    ['business_revenue' => ['operator' => '>=', 'value' => 50000]],
                    ['personal_income' => ['operator' => '>=', 'value' => 25000]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_REVENUE_WEAK,
                'subscore' => 25,
                'order' => 4,
                'conditions' => [
                    ['business_revenue' => ['operator' => '<', 'value' => 50000]],
                    ['personal_income' => ['operator' => '<', 'value' => 25000]],
                ]
            ],
        ]));

        // Bureau Depth
        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_TRADELINE_DEPTH, [
            [
                'label' => ScoreConfiguration::STATUS_TRADE_MULTIPLE_HIGH,
                'subscore' => 100,
                'order' => 1,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 5]],
                    ['high_limit_accounts' => ['operator' => '>=', 'value' => 2]],
                    ['limit' => ['operator' => '>=', 'value' => 15000]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH,
                'subscore' => 80,
                'order' => 2,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 3]],
                    ['high_limit_accounts' => ['operator' => '>=', 'value' => 1]],
                    ['limit' => ['operator' => '>=', 'value' => 10000]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_TRADE_MODERATE,
                'subscore' => 60,
                'order' => 3,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 2]],
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_TRADE_THIN,
                'subscore' => 35,
                'order' => 4,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 0]],
                ]
            ],
        ]));

        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS, [
            [
                'label' => ScoreConfiguration::STATUS_BANKING_STRONG,
                'subscore' => 100,
                'order' => 1,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 2]],
                    ['checking_months' => ['operator' => '>', 'value' => 12]],
                    ['each' => true]
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_BANKING_MODERATE,
                'subscore' => 75,
                'order' => 2,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 1]],
                    ['checking_months' => ['operator' => '>', 'value' => 12]],
                    ['each' => false]
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_BANKING_LIMITED,
                'subscore' => 50,
                'order' => 3,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 1]],
                    ['each' => false]
                ]
            ],
            [
                'label' => ScoreConfiguration::STATUS_BANKING_NONE,
                'subscore' => 20,
                'order' => 4,
                'conditions' => [
                    ['accounts' => ['operator' => '=', 'value' => 0]],
                    ['each' => false]
                ]
            ],
        ]));

        $items->push(...$this->tiers(ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH, [
            [
                'label' => ScoreConfiguration::STATUS_STARTUP_EXCEPTION,
                'subscore' => 55,
                'order' => 1,
                'conditions' => [
                    ['business_revenue' => ['operator' => '<=', 'value' => 0]],
                    ['business_age' => ['operator' => '<=', 'value' => 24]],
                ]
            ]
        ]));

        foreach ($items as $item) {
            ScoreConfiguration::create($item);
        }
    }

    private function seedReadinessBands(): void
    {
        // Bands form a contiguous score range from 0 to 850
        // Each band's max is computed as (next band's min - 1), last band goes to 0
        $rows = [
            ['label' => ScoreConfiguration::BAND_ELITE_READY, 'min' => 90, 'color' => '#198754', 'order' => 1],
            ['label' => ScoreConfiguration::BAND_EXECUTIVE_READY, 'min' => 80, 'color' => '#0d6efd', 'order' => 2],
            ['label' => ScoreConfiguration::BAND_FOUNDATION_READY, 'min' => 70, 'color' => '#ffc107', 'order' => 3],
            ['label' => ScoreConfiguration::BAND_CONDITIONAL, 'min' => 60, 'color' => '#fd7e14', 'order' => 4],
            ['label' => ScoreConfiguration::BAND_NOT_READY, 'color' => '#dc3545', 'order' => 5],
        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create([
                'category' => ScoreConfiguration::CATEGORY_READINESS_BAND,
                'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
                'label' => $row['label'],
                'color' => $row['color'] ?? null,
                'min_value' => $row['min'] ?? null,
                'sort_order' => $row['order'],
            ]);
        }
    }

    private function tiers(string $factorSlug, array $tiers): array
    {
        $rows = [];

        foreach ($tiers as $tier) {
            $rows[] = [
                'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
                'factor_slug' => $factorSlug,
                'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
                'label' => $tier['label'] ?? null,
                'min_value' => $tier['min'] ?? null,
                'max_value' => $tier['max'] ?? null,
                'numeric_value' => $tier['subscore'] ?? null,
                'conditions' => isset($tier['conditions']) ? $tier['conditions'] : null,
                'sort_order' => $tier['order'] ?? 0,
            ];
        }

        return $rows;
    }

    private function seedApprovalProbabilityTiers(): void
    {
        $items = collect();

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_FICO_ABOVE_MINIMUM, [
            ['min' => 40, 'points' => 7, 'label' => '40+ points above minimum', 'order' => 1],
            ['min' => 20, 'max' => 39, 'points' => 4, 'label' => '20–39 points above minimum', 'order' => 2],
            ['min' => 0, 'max' => 19, 'points' => 0, 'label' => '0–19 points above minimum', 'order' => 3],
        ]));

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_UTILIZATION, [
            ['min' => 0, 'max' => 5, 'points' => 5, 'label' => '0–5%', 'order' => 1],
            ['min' => 5.01, 'max' => 10, 'points' => 3, 'label' => '5.01–10%', 'order' => 2],
            ['min' => 10.01, 'max' => 20, 'points' => 0, 'label' => '10.01–20%', 'order' => 3],
            ['min' => 20.01, 'max' => 25, 'points' => -5, 'label' => '20.01–25%', 'order' => 4],
            ['min' => 25.01, 'points' => -10, 'label' => '25.01%+', 'order' => 5],
        ]));

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_RECENT_INQUIRIES, [
            ['min' => 0, 'max' => 2, 'points' => 3, 'label' => '0–2 inquiries', 'order' => 1],
            ['min' => 3, 'max' => 4, 'points' => 0, 'label' => '3–4 inquiries', 'order' => 2],
            ['min' => 5, 'points' => -8, 'label' => '5+ inquiries', 'order' => 3],
        ]));

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_BANKING_RELATIONSHIP, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_STRONG, 'order' => 1],
            ['points' => 2, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_MODERATE, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_LIMITED, 'order' => 3],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_NONE, 'order' => 4],
        ]));

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_TRADELINE_DEPTH, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_MULTIPLE_HIGH, 'order' => 1],
            ['points' => 3, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_GOOD_DEPTH, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_MODERATE, 'order' => 3],
            ['points' => -10, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_THIN, 'order' => 4],
        ]));

        $items->push(...$this->probTiers(ScoreConfiguration::FACTOR_REVENUE_INCOME, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG, 'order' => 1],
            ['points' => 2, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_MODERATE, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED, 'order' => 3],
            ['points' => -5, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_STARTUP, 'order' => 4],
        ]));

        foreach ($items as $item) {
            ScoreConfiguration::create($item);
        }
    }

    private function probTiers(string $factorSlug, array $tiers): array
    {
        return $this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY, $factorSlug, $tiers);
    }

    private function modelTiers(string $modelSlug, string $factorSlug, array $tiers): array
    {
        $rows = [];
        foreach ($tiers as $tier) {
            $rows[] = [
                'model_slug' => $modelSlug,
                'factor_slug' => $factorSlug,
                'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
                'label' => $tier['label'] ?? null,
                'min_value' => $tier['min'] ?? null,
                'max_value' => $tier['max'] ?? null,
                'numeric_value' => $tier['points'] ?? null,
                'conditions' => null,
                'sort_order' => $tier['order'] ?? 0,
            ];
        }
        return $rows;
    }

    private function seedApprovalOddsTiers(): void
    {
        $items = collect();

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_FICO_ABOVE_MINIMUM, [
            ['min' => 40, 'points' => 7, 'label' => '40+ points above minimum', 'order' => 1],
            ['min' => 20, 'max' => 39, 'points' => 4, 'label' => '20–39 points above minimum', 'order' => 2],
            ['min' => 0, 'max' => 19, 'points' => 0, 'label' => '0–19 points above minimum', 'order' => 3],
        ]));

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_UTILIZATION, [
            ['min' => 0, 'max' => 5, 'points' => 5, 'label' => '0–5%', 'order' => 1],
            ['min' => 5.01, 'max' => 10, 'points' => 3, 'label' => '5.01–10%', 'order' => 2],
            ['min' => 10.01, 'max' => 20, 'points' => 0, 'label' => '10.01–20%', 'order' => 3],
            ['min' => 20.01, 'max' => 25, 'points' => -5, 'label' => '20.01–25%', 'order' => 4],
            ['min' => 25.01, 'points' => -10, 'label' => '25.01%+', 'order' => 5],
        ]));

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_RECENT_INQUIRIES, [
            ['min' => 0, 'max' => 2, 'points' => 3, 'label' => '0–2 inquiries', 'order' => 1],
            ['min' => 3, 'max' => 4, 'points' => 0, 'label' => '3–4 inquiries', 'order' => 2],
            ['min' => 5, 'points' => -8, 'label' => '5+ inquiries', 'order' => 3],
        ]));

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_BANKING_RELATIONSHIP, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_STRONG, 'order' => 1],
            ['points' => 2, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_MODERATE, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_LIMITED, 'order' => 3],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_BANKING_NONE, 'order' => 4],
        ]));

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_TRADELINE_DEPTH, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_MULTIPLE_HIGH, 'order' => 1],
            ['points' => 3, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_GOOD_DEPTH, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_MODERATE, 'order' => 3],
            ['points' => -10, 'label' => ScoreConfiguration::PROB_STATUS_TRADE_THIN, 'order' => 4],
        ]));

        $items->push(...$this->modelTiers(ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::FACTOR_REVENUE_INCOME, [
            ['points' => 5, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG, 'order' => 1],
            ['points' => 2, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_MODERATE, 'order' => 2],
            ['points' => 0, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_LIMITED, 'order' => 3],
            ['points' => -5, 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_STARTUP, 'order' => 4],
        ]));

        foreach ($items as $item) {
            ScoreConfiguration::create($item);
        }
    }

    private function seedTimeSettings(): void
    {
        $rows = [
            ['label' => ScoreConfiguration::TIME_LABEL_MORNING, 'start' => 6.0, 'end' => 11.0, 'order' => 1],
            ['label' => ScoreConfiguration::TIME_LABEL_MIDDAY, 'start' => 11.0, 'end' => 14.0, 'order' => 2],
            ['label' => ScoreConfiguration::TIME_LABEL_AFTERNOON, 'start' => 14.0, 'end' => 17.0, 'order' => 3],
            ['label' => ScoreConfiguration::TIME_LABEL_EVENING, 'start' => 17.0, 'end' => 21.0, 'order' => 4],
            ['label' => ScoreConfiguration::TIME_LABEL_ANYTIME, 'start' => 9.0, 'end' => 17.0, 'order' => 5],
        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create([
                'category' => ScoreConfiguration::CATEGORY_TIME,
                'model_slug' => ScoreConfiguration::MODEL_GENERAL_SETTING,
                'factor_slug' => ScoreConfiguration::FACTOR_TIME_SETTING,
                'label' => $row['label'],
                'min_value' => $row['start'],
                'max_value' => $row['end'],
                'conditions' => [
                    'start' => $row['start'],
                    'end' => $row['end'],
                ],
                'sort_order' => $row['order'],
            ]);
        }
    }
}
