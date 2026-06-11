<?php

namespace Database\Seeders;

use App\Models\Common\ScoreConfigItem;
use App\Models\Common\ScoreFactor;
use App\Models\Common\ScoreModel;
use App\Models\Common\ScoreModelFactor;
use Illuminate\Database\Seeder;

class ScoreConfigurationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedModels();
        $this->seedFactors();
        $this->seedModelFactors();
        $this->seedSubscoreTiers();
        $this->seedBandMappings();
        $this->seedReadinessBands();
        $this->seedPlayEligibility();
    }

    private function seedModels(): void
    {
        ScoreModel::upsert([
            ['slug' => ScoreModel::SLUG_FUNDING_READINESS, 'name' => 'Funding Readiness', 'description' => 'Overall business funding readiness score'],
            ['slug' => ScoreModel::SLUG_CARD_APPROVAL_ODDS, 'name' => 'Card Approval Odds', 'description' => 'Credit card approval probability score'],
        ], ['slug'], ['name', 'description']);
    }

    private function seedFactors(): void
    {
        ScoreFactor::upsert([
            ['slug' => 'fico', 'name' => 'FICO Score', 'description' => 'Personal credit score', 'sort_order' => 1],
            ['slug' => 'utilization', 'name' => 'Credit Card Utilization', 'description' => 'Revolving credit utilization ratio', 'sort_order' => 2],
            ['slug' => 'recent_inquiries', 'name' => 'Recent Inquiries/New Accounts', 'description' => 'Recent credit inquiries and new accounts', 'sort_order' => 3],
            ['slug' => 'time_in_business', 'name' => 'Time In Business', 'description' => 'Length of time business has been operating', 'sort_order' => 4],
            ['slug' => 'revenue_strength', 'name' => 'Revenue Strength', 'description' => 'Business revenue and personal income strength', 'sort_order' => 5],
            ['slug' => 'tradeline_depth', 'name' => 'Tradeline Depth', 'description' => 'Credit account depth and limits', 'sort_order' => 6],
            ['slug' => 'banking_relationships', 'name' => 'Banking Relationships', 'description' => 'Banking relationship strength', 'sort_order' => 7],
        ], ['slug'], ['name', 'description', 'sort_order']);
    }

    private function seedModelFactors(): void
    {
        $card = ScoreModel::where('slug', ScoreModel::SLUG_CARD_APPROVAL_ODDS)->value('id');
        $readiness = ScoreModel::where('slug', ScoreModel::SLUG_FUNDING_READINESS)->value('id');

        $factor = fn(string $slug) => ScoreFactor::where('slug', $slug)->value('id');

        // Card Approval Odds weights
        ScoreModelFactor::upsert([
            ['score_model_id' => $card, 'score_factor_id' => $factor('fico'), 'weight' => 40, 'sort_order' => 1],
            ['score_model_id' => $card, 'score_factor_id' => $factor('utilization'), 'weight' => 25, 'sort_order' => 2],
            ['score_model_id' => $card, 'score_factor_id' => $factor('recent_inquiries'), 'weight' => 15, 'sort_order' => 3],
            ['score_model_id' => $card, 'score_factor_id' => $factor('time_in_business'), 'weight' => 10, 'sort_order' => 4],
            ['score_model_id' => $card, 'score_factor_id' => $factor('revenue_strength'), 'weight' => 10, 'sort_order' => 5],
        ], ['score_model_id', 'score_factor_id'], ['weight', 'sort_order']);

        // Funding Readiness weights
        ScoreModelFactor::upsert([
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('fico'), 'weight' => 20, 'sort_order' => 1],
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('utilization'), 'weight' => 15, 'sort_order' => 2],
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('time_in_business'), 'weight' => 25, 'sort_order' => 3],
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('revenue_strength'), 'weight' => 20, 'sort_order' => 4],
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('tradeline_depth'), 'weight' => 10, 'sort_order' => 5],
            ['score_model_id' => $readiness, 'score_factor_id' => $factor('banking_relationships'), 'weight' => 10, 'sort_order' => 6],
        ], ['score_model_id', 'score_factor_id'], ['weight', 'sort_order']);
    }

    private function seedSubscoreTiers(): void
    {
        ScoreConfigItem::where('category', ScoreConfigItem::CATEGORY_SUBSCORE_TIER)->delete();

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

        // Revenue tiers (with compound conditions)
        $items->push(...$this->tiers('revenue_strength', [
            ['label' => 'Strong', 'subscore' => 100, 'order' => 1, 'conditions' => ['any' => [
                ['field' => 'business_revenue_monthly', 'operator' => '>=', 'value' => 25000],
                ['field' => 'personal_income_annual', 'operator' => '>=', 'value' => 150000],
            ]]],
            ['label' => 'Moderate', 'subscore' => 75, 'order' => 2, 'conditions' => ['any' => [
                ['field' => 'business_revenue_monthly', 'operator' => '>=', 'value' => 10000],
                ['field' => 'personal_income_annual', 'operator' => '>=', 'value' => 75000],
            ]]],
            ['label' => 'Limited', 'subscore' => 55, 'order' => 3, 'conditions' => ['any' => [
                ['field' => 'business_revenue_monthly', 'operator' => '>=', 'value' => 1000],
                ['field' => 'personal_income_annual', 'operator' => '>=', 'value' => 40000],
            ]]],
            ['label' => 'Weak', 'subscore' => 25, 'order' => 4],
        ]));

        // Tradeline Depth tiers (with compound AND conditions)
        $items->push(...$this->tiers('tradeline_depth', [
            ['label' => 'Multiple High-Limit', 'subscore' => 100, 'order' => 1, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => '>=', 'value' => 5],
                ['field' => 'high_limit_accounts', 'operator' => '>=', 'value' => 2],
                ['field' => 'avg_high_limit', 'operator' => '>=', 'value' => 15000],
            ]]],
            ['label' => 'Good Depth', 'subscore' => 80, 'order' => 2, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => '>=', 'value' => 3],
                ['field' => 'high_limit_accounts', 'operator' => '>=', 'value' => 1],
                ['field' => 'avg_high_limit', 'operator' => '>=', 'value' => 10000],
            ]]],
            ['label' => 'Moderate', 'subscore' => 60, 'order' => 3, 'conditions' => ['all' => [
                ['field' => 'open_accounts', 'operator' => 'between', 'value' => [2, 3]],
            ]]],
            ['label' => 'Thin', 'subscore' => 35, 'order' => 4],
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

        ScoreConfigItem::insert($items->toArray());
    }

    private function seedBandMappings(): void
    {
        $cardModel = ScoreModel::where('slug', ScoreModel::SLUG_CARD_APPROVAL_ODDS)->value('id');

        $rows = [
            ['score_model_id' => $cardModel, 'max_value' => null, 'label' => '90+', 'min_value' => 90, 'numeric_value' => 95, 'sort_order' => 1],
            ['score_model_id' => $cardModel, 'max_value' => 89, 'label' => '80-89', 'min_value' => 80, 'numeric_value' => 85, 'sort_order' => 2],
            ['score_model_id' => $cardModel, 'max_value' => 79, 'label' => '70-79', 'min_value' => 70, 'numeric_value' => 75, 'sort_order' => 3],
            ['score_model_id' => $cardModel, 'max_value' => 69, 'label' => '60-69', 'min_value' => 60, 'numeric_value' => 65, 'sort_order' => 4],
            ['score_model_id' => $cardModel, 'max_value' => 59, 'label' => '50-59', 'min_value' => 50, 'numeric_value' => 50, 'sort_order' => 5],
            ['score_model_id' => $cardModel, 'max_value' => 49, 'label' => 'Under 50', 'min_value' => null, 'numeric_value' => 25, 'sort_order' => 6],
        ];

        $this->upsertWithFixedKeys($rows, ScoreConfigItem::CATEGORY_BAND_MAPPING);
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

        $this->upsertWithFixedKeys($rows, ScoreConfigItem::CATEGORY_READINESS_BAND);
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

        $this->upsertWithFixedKeys($rows, ScoreConfigItem::CATEGORY_PLAY_ELIGIBILITY);
    }

    private function upsertWithFixedKeys(array $rows, string $category): void
    {
        if (empty($rows)) return;

        ScoreConfigItem::where('category', $category)->delete();

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = array_merge([
                'partner_id' => null,
                'score_model_id' => null,
                'factor_slug' => null,
                'category' => $category,
                'label' => null,
                'min_value' => null,
                'max_value' => null,
                'numeric_value' => null,
                'conditions' => null,
                'sort_order' => 0,
            ], $row);
        }

        ScoreConfigItem::insert($normalized);
    }

    private function tiers(string $factorSlug, array $tiers): array
    {
        $rows = [];

        foreach ($tiers as $tier) {
            $rows[] = [
                'partner_id' => null,
                'score_model_id' => null,
                'factor_slug' => $factorSlug,
                'category' => ScoreConfigItem::CATEGORY_SUBSCORE_TIER,
                'label' => $tier['label'] ?? null,
                'min_value' => $tier['min'] ?? null,
                'max_value' => $tier['max'] ?? null,
                'numeric_value' => $tier['subscore'] ?? null,
                'conditions' => isset($tier['conditions']) ? json_encode($tier['conditions']) : null,
                'sort_order' => $tier['order'] ?? 0,
            ];
        }

        return $rows;
    }
}
