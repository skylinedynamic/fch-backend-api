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
        $this->seedBandMappings();
        $this->seedReadinessBands();
        $this->seedPlayEligibility();
    }

    private function seedModelWeights(): void
    {
        $rows = [
            // Card Approval Odds weights
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'card_approval_odds', 'factor_slug' => 'fico', 'numeric_value' => 40, 'sort_order' => 1],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'card_approval_odds', 'factor_slug' => 'utilization', 'numeric_value' => 25, 'sort_order' => 2],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'card_approval_odds', 'factor_slug' => 'recent_inquiries', 'numeric_value' => 15, 'sort_order' => 3],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'card_approval_odds', 'factor_slug' => 'time_in_business', 'numeric_value' => 10, 'sort_order' => 4],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'card_approval_odds', 'factor_slug' => 'revenue_strength', 'numeric_value' => 10, 'sort_order' => 5],

            // Funding Readiness weights
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'fico',                 'numeric_value' => 35, 'sort_order' => 1],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'utilization',          'numeric_value' => 25, 'sort_order' => 2],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'time_in_business',     'numeric_value' => 15, 'sort_order' => 3],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'revenue_strength',     'numeric_value' => 10, 'sort_order' => 4],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'tradeline_depth',      'numeric_value' => 10, 'sort_order' => 5],
            ['category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT, 'model_slug' => 'funding_readiness', 'factor_slug' => 'banking_relationships', 'numeric_value' =>  5, 'sort_order' => 6],

        ];

        foreach ($rows as $row) {
            ScoreConfiguration::create($row);
        }
    }

    private function seedSubscoreTiers(): void
    {
        $items = collect();

        // FICO tiers
        $items->push(...$this->tiers('fico', [
            ['label' => '780+', 'min' => 780, 'subscore' => 100, 'order' => 1],
            ['label' => '760-779', 'min' => 760, 'max' => 779, 'subscore' => 95, 'order' => 2],
            ['label' => '740-759', 'min' => 740, 'max' => 759, 'subscore' => 90, 'order' => 3],
            ['label' => '720-739', 'min' => 720, 'max' => 739, 'subscore' => 80, 'order' => 4],
            ['label' => '700-719', 'min' => 700, 'max' => 719, 'subscore' => 70, 'order' => 5],
            ['label' => '680-699', 'min' => 680, 'max' => 699, 'subscore' => 55, 'order' => 6],
            ['label' => '660-679', 'min' => 660, 'max' => 679, 'subscore' => 40, 'order' => 7],
            ['label' => 'Below 660', 'max' => 659, 'subscore' => 20, 'order' => 8],
        ]));

        // Utilization tiers
        $items->push(...$this->tiers('utilization', [
            ['label' => '0-5%', 'max' => 5, 'subscore' => 100, 'order' => 1],
            ['label' => '5-10%', 'min' => 5.01, 'max' => 10, 'subscore' => 90, 'order' => 2],
            ['label' => '10-20%', 'min' => 10.01, 'max' => 20, 'subscore' => 75, 'order' => 3],
            ['label' => '20-30%', 'min' => 20.01, 'max' => 30, 'subscore' => 60, 'order' => 4],
            ['label' => '30-40%', 'min' => 30.01, 'max' => 40, 'subscore' => 35, 'order' => 5],
            ['label' => 'Above 40%', 'min' => 40.01, 'subscore' => 10, 'order' => 6],
        ]));

        // Time in Business tiers
        $items->push(...$this->tiers('time_in_business', [
            ['label' => '36+ months', 'min' => 36, 'subscore' => 100, 'order' => 1],
            ['label' => '24-35 months', 'min' => 24, 'max' => 35, 'subscore' => 85, 'order' => 2],
            ['label' => '12-23 months', 'min' => 12, 'max' => 23, 'subscore' => 70, 'order' => 3],
            ['label' => '6-11 months', 'min' => 6, 'max' => 11, 'subscore' => 50, 'order' => 4],
            ['label' => 'Below 6 months', 'max' => 5, 'subscore' => 25, 'order' => 5],
        ]));

        // Revenue tiers
        $items->push(...$this->tiers('revenue_strength', [
            ['label' => 'Strong', 'min' => 500000, 'subscore' => 100, 'order' => 1],
            ['label' => 'Moderate', 'min' => 150000, 'max' => 499999, 'subscore' => 75, 'order' => 2],
            ['label' => 'Limited', 'min' => 50000, 'max' => 149999, 'subscore' => 55, 'order' => 3],
            ['label' => 'Weak', 'max' => 49999, 'subscore' => 25, 'order' => 4],
        ]));

        // Tradeline Depth tiers (with compound AND conditions)
        $items->push(...$this->tiers('tradeline_depth', [
            ['label' => 'Multiple High-Limit', 'subscore' => 100, 'order' => 1, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => '>=', 'value' => 5],
                ['field' => 'high_limit_accounts', 'operator' => '>=', 'value' => 2],
                ['field' => 'limit', 'operator' => '>=', 'value' => 15000],
            ]]],
            ['label' => 'Good Depth', 'subscore' => 80, 'order' => 2, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => '>=', 'value' => 3],
                ['field' => 'high_limit_accounts', 'operator' => '>=', 'value' => 1],
                ['field' => 'limit', 'operator' => '>=', 'value' => 10000],
            ]]],
            ['label' => 'Moderate', 'subscore' => 60, 'order' => 3, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => 'between', 'value' => [2, 3]],
            ]]],
            ['label' => 'Thin', 'subscore' => 35, 'order' => 4, 'conditions' => ['any' => [
                ['field' => 'open_accounts', 'operator' => 'between', 'value' => [0, 1]],
                ['field' => 'high_limit_accounts', 'operator' => '=', 'value' => 0],
                ['field' => 'limit', 'operator' => '=', 'value' => 0],
            ]]],
        ]));

        // Banking Relationship tiers
        $items->push(...$this->tiers('banking_relationships', [
            ['label' => 'Strong Multi-Year', 'subscore' => 100, 'order' => 1, 'conditions' => ['all' => [
                ['field' => 'checking_years', 'operator' => '>=', 'value' => 3],
                ['field' => 'has_savings_or_loc', 'operator' => '=', 'value' => true],
            ]]],
            ['label' => 'Established', 'subscore' => 80, 'order' => 2, 'conditions' => ['all' => [
                ['field' => 'checking_months', 'operator' => '>=', 'value' => 12],
                ['field' => 'has_regular_deposits', 'operator' => '=', 'value' => true],
            ]]],
            ['label' => 'Basic', 'subscore' => 60, 'order' => 3, 'conditions' => ['all' => [
                ['field' => 'checking_months', 'operator' => '<', 'value' => 12],
            ]]],
            ['label' => 'Weak / None', 'subscore' => 35, 'order' => 4],
        ]));

        foreach ($items as $item) {
            ScoreConfiguration::create($item);
        }
    }

    private function seedBandMappings(): void
    {
        $rows = [
            ['model_slug' => 'card_approval_odds', 'max_value' => null, 'label' => '90+', 'min_value' => 90, 'numeric_value' => 95, 'sort_order' => 1],
            ['model_slug' => 'card_approval_odds', 'max_value' => 89, 'label' => '80-89', 'min_value' => 80, 'numeric_value' => 85, 'sort_order' => 2],
            ['model_slug' => 'card_approval_odds', 'max_value' => 79, 'label' => '70-79', 'min_value' => 70, 'numeric_value' => 75, 'sort_order' => 3],
            ['model_slug' => 'card_approval_odds', 'max_value' => 69, 'label' => '60-69', 'min_value' => 60, 'numeric_value' => 65, 'sort_order' => 4],
            ['model_slug' => 'card_approval_odds', 'max_value' => 59, 'label' => '50-59', 'min_value' => 50, 'numeric_value' => 50, 'sort_order' => 5],
            ['model_slug' => 'card_approval_odds', 'max_value' => 49, 'label' => 'Under 50', 'min_value' => null, 'numeric_value' => 25, 'sort_order' => 6],
        ];

        $this->insertWithCategory($rows, ScoreConfiguration::CATEGORY_BAND_MAPPING);
    }

    private function seedReadinessBands(): void
    {
        $rows = [
            ['max_value' => 100, 'label' => 'Elite Ready', 'min_value' => 90, 'sort_order' => 1],
            ['max_value' => 89, 'label' => 'Executive Ready', 'min_value' => 80, 'sort_order' => 2],
            ['max_value' => 79, 'label' => 'Foundation Ready', 'min_value' => 70, 'sort_order' => 3],
            ['max_value' => 69, 'label' => 'Conditional', 'min_value' => 60, 'sort_order' => 4],
            ['max_value' => 59, 'label' => 'Not Ready', 'min_value' => 0, 'sort_order' => 5],
        ];

        $this->insertWithCategory($rows, ScoreConfiguration::CATEGORY_READINESS_BAND);
    }

    private function seedPlayEligibility(): void
    {
        $rows = [
            ['max_value' => null, 'min_value' => null, 'label' => 'Recovery & Rebuild Play', 'numeric_value' => 680, 'sort_order' => 1],
            ['max_value' => null, 'min_value' => null, 'label' => 'Foundation 100 Play', 'numeric_value' => 700, 'sort_order' => 2],
            ['max_value' => null, 'min_value' => null, 'label' => 'Startup Launch Play', 'numeric_value' => 700, 'sort_order' => 3],
            ['max_value' => null, 'min_value' => null, 'label' => 'Zero-Cost Capital Play', 'numeric_value' => 700, 'sort_order' => 4],
            ['max_value' => null, 'min_value' => null, 'label' => 'Relationship Banking Play', 'numeric_value' => 700, 'sort_order' => 5],
            ['max_value' => null, 'min_value' => null, 'label' => 'Investor Liquidity Play', 'numeric_value' => 720, 'sort_order' => 6],
            ['max_value' => null, 'min_value' => null, 'label' => 'Executive 250 Play', 'numeric_value' => 740, 'sort_order' => 7],
            ['max_value' => null, 'min_value' => null, 'label' => 'Business Acquisition Play', 'numeric_value' => 740, 'sort_order' => 8],
            ['max_value' => null, 'min_value' => null, 'label' => 'Elite 500 Play', 'numeric_value' => 760, 'sort_order' => 9],
        ];

        $this->insertWithCategory($rows, ScoreConfiguration::CATEGORY_PLAY_ELIGIBILITY);
    }

    private function insertWithCategory(array $rows, string $category): void
    {
        foreach ($rows as $row) {
            $data = array_merge([
                'model_slug' => null,
                'factor_slug' => null,
                'category' => $category,
                'label' => null,
                'min_value' => null,
                'max_value' => null,
                'numeric_value' => null,
                'conditions' => null,
                'sort_order' => 0,
            ], $row);

            ScoreConfiguration::create($data);
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
