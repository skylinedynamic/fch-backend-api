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
    }

    private function seedModelWeights(): void
    {
        $rows = [
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'fico', 'numeric_value' => 35, 'sort_order' => 1],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'utilization', 'numeric_value' => 25, 'sort_order' => 2],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'time_in_business', 'numeric_value' => 15, 'sort_order' => 3],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'revenue_strength', 'numeric_value' => 10, 'sort_order' => 4],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'tradeline_depth', 'numeric_value' => 10, 'sort_order' => 5],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'banking_relationships', 'numeric_value' => 5, 'sort_order' => 6],
        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create($row);
        }
    }

    private function seedSubscoreTiers(): void
    {
        $items = collect();

        $items->push(...$this->tiers('fico', [
            ['min' => 780, 'subscore' => 100, 'order' => 1],
            ['min' => 760, 'max' => 779, 'subscore' => 95, 'order' => 2],
            ['min' => 740, 'max' => 759, 'subscore' => 90, 'order' => 3],
            ['min' => 720, 'max' => 739, 'subscore' => 80, 'order' => 4],
            ['min' => 700, 'max' => 719, 'subscore' => 70, 'order' => 5],
            ['min' => 680, 'max' => 699, 'subscore' => 55, 'order' => 6],
            ['min' => 660, 'max' => 679, 'subscore' => 40, 'order' => 7],
            ['max' => 659, 'subscore' => 20, 'order' => 8],
        ]));

        $items->push(...$this->tiers('utilization', [
            ['max' => 5, 'subscore' => 100, 'order' => 1],
            ['min' => 5.01, 'max' => 10, 'subscore' => 90, 'order' => 2],
            ['min' => 10.01, 'max' => 20, 'subscore' => 75, 'order' => 3],
            ['min' => 20.01, 'max' => 30, 'subscore' => 60, 'order' => 4],
            ['min' => 30.01, 'max' => 40, 'subscore' => 35, 'order' => 5],
            ['min' => 40.01, 'subscore' => 10, 'order' => 6],
        ]));

        $items->push(...$this->tiers('time_in_business', [
            ['min' => 36, 'subscore' => 100, 'order' => 1],
            ['min' => 24, 'max' => 35, 'subscore' => 85, 'order' => 2],
            ['min' => 12, 'max' => 23, 'subscore' => 70, 'order' => 3],
            ['min' => 6, 'max' => 11, 'subscore' => 50, 'order' => 4],
            ['max' => 5, 'subscore' => 25, 'order' => 5],
        ]));

        // Financial Capacity
        $items->push(...$this->tiers('revenue_strength', [
            ['label' => 'Strong', 'min' => 500000, 'subscore' => 100, 'order' => 1],
            ['label' => 'Moderate', 'min' => 150000, 'max' => 499999, 'subscore' => 75, 'order' => 2],
            ['label' => 'Limited', 'min' => 50000, 'max' => 149999, 'subscore' => 55, 'order' => 3],
            ['label' => 'Weak', 'max' => 49999, 'subscore' => 25, 'order' => 4],
        ]));

        // Bureau Depth
        $items->push(...$this->tiers('tradeline_depth', [
            [
                'label' => 'Multiple High-Limit & Installment Depth',
                'subscore' => 100,
                'order' => 1,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 5]],
                    ['high_limit_accounts' => ['operator' => '>=', 'value' => 2]],
                    ['limit' => ['operator' => '>=', 'value' => 15000]],
                ]
            ],
            [
                'label' => 'Good Depth with High-Limit account',
                'subscore' => 80,
                'order' => 2,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 3]],
                    ['high_limit_accounts' => ['operator' => '>=', 'value' => 1]],
                    ['limit' => ['operator' => '>=', 'value' => 10000]],
                ]
            ],
            [
                'label' => 'Moderate Depth',
                'subscore' => 60,
                'order' => 3,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 2]],
                ]
            ],
            [
                'label' => 'Thin File',
                'subscore' => 35,
                'order' => 4,
                'conditions' => [
                    ['open_accounts' => ['operator' => '>=', 'value' => 0]],
                ]
            ],
        ]));

        $items->push(...$this->tiers('banking_relationships', [
            [
                'subscore' => 100,
                'order' => 1,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 2]],
                    ['checking_months' => ['operator' => '>', 'value' => 12]],
                    ['each' => true]
                ]
            ],
            [
                'subscore' => 75,
                'order' => 2,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 1]],
                    ['checking_months' => ['operator' => '>', 'value' => 12]],
                    ['each' => false]
                ]
            ],
            [
                'subscore' => 50,
                'order' => 3,
                'conditions' => [
                    ['accounts' => ['operator' => '>=', 'value' => 1]],
                    ['each' => false]
                ]
            ],
            [
                'subscore' => 20,
                'order' => 4,
                'conditions' => [
                    ['accounts' => ['operator' => '=', 'value' => 0]],
                    ['each' => false]
                ]
            ],
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
            ['label' => 'Elite Ready', 'min' => 720, 'color' => '#198754', 'order' => 1],
            ['label' => 'Executive Ready', 'min' => 680, 'color' => '#0d6efd', 'order' => 2],
            ['label' => 'Foundation Ready', 'min' => 640, 'color' => '#ffc107', 'order' => 3],
            ['label' => 'Conditional', 'min' => 580, 'color' => '#fd7e14', 'order' => 4],
            ['label' => 'Not ready', 'color' => '#dc3545', 'order' => 5],
        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create([
                'category' => ScoreConfiguration::CATEGORY_READINESS_BAND,
                'model_slug' => 'funding_readiness',
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
                'model_slug' => 'funding_readiness',
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
}
