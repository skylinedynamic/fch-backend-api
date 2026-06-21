<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReadinessScoreCalculationService
{
    public function calculate(Borrower $borrower): array
    {
        $factors = [
            'fico' => ['label' => 'Personal Credit', 'slug' => 'fico'],
            'utilization' => ['label' => 'Utilization', 'slug' => 'utilization'],
            'time_in_business' => ['label' => 'Time in Business', 'slug' => 'time_in_business'],
            'revenue_strength' => ['label' => 'Revenue Strength', 'slug' => 'revenue_strength'],
            'tradeline_depth' => ['label' => 'Tradeline Depth', 'slug' => 'tradeline_depth'],
            'banking_relationships' => ['label' => 'Banking Relationships', 'slug' => 'banking_relationships'],
        ];

        $factorScores = [];
        $totalScore = 0;

        foreach ($factors as $key => $info) {
            $method = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Subscore';
            $weightedScore = $this->$method($borrower);
            $weight = $this->getFactorWeight($key);
            $rawScore = $weight > 0 ? round($weightedScore['score'] / ($weight / 100), 2) : 0;
            $factorScores[] = [
                'slug' => $info['slug'],
                'label' => $info['label'],
                'value' => $weightedScore['value'],
                'weighted_score' => round($weightedScore['score'], 2),
                'raw_score' => $rawScore,
                'status' => $weightedScore['status'] ?? null
            ];
            $totalScore += $weightedScore['score'];
        }

        $totalScore = (int) round($totalScore) > 100 ? 100 : (int) round($totalScore);

        $band = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)
            ->where('model_slug', 'funding_readiness')
            ->orderBy('sort_order')
            ->get()
            ->first(fn($b) => $b->min_value === null || $totalScore >= (float) $b->min_value);

        return [
            'total_score' => $totalScore,
            'score_label' => $band?->label ?? 'Not Ready',
            'score_label_color' => $band?->color ?? '#6c757d',
            'factors' => $factorScores,
        ];
    }

    public function getSubscoreForFactor(string $slug, Borrower $borrower): float
    {
        $methodMap = [
            'fico' => 'getFicoSubscore',
            'utilization' => 'getUtilizationSubscore',
            'time_in_business' => 'getTimeInBusinessSubscore',
            'revenue_strength' => 'getRevenueStrengthSubscore',
            'tradeline_depth' => 'getTradelineDepthSubscore',
            'banking_relationships' => 'getBankingRelationshipsSubscore',
        ];

        if (!isset($methodMap[$slug])) {
            return 0;
        }

        $weight = $this->getFactorWeight($slug);
        $weightedScore = $this->{$methodMap[$slug]}($borrower);
        $score = is_array($weightedScore) ? $weightedScore['score'] : (float) $weightedScore;

        return $score / ($weight > 0 ? ($weight / 100) : 1);
    }

    private function getFactorWeight(string $slug): float
    {
        $weight = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', $slug)
            ->value('numeric_value');

        return $weight !== null ? (float) $weight : 0;
    }

    private function getFicoSubscore(Borrower $borrower)
    {
        $fico = null;

        if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
            $fico = (int) $borrower->creditProfile->fico_score;
        } elseif ($borrower->fico_score !== null) {
            $fico = (int) $borrower->fico_score;
        }

        if ($fico === null) {
            $fico = 0;
        }

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'fico')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'fico')
            ->orderBy('sort_order')
            ->get();

        $value = 0;
        foreach ($tiers as $tier) {
            $minMatched = ($tier->min_value === null || $fico >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $fico <= (float) $tier->max_value);

            if ($minMatched && $maxMatched) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $fico,
            'status' => null
        ];
    }

    private function getUtilizationSubscore(Borrower $borrower)
    {
        $utilization = null;

        if ($borrower->creditProfile) {
            $analysis = $borrower->creditProfile->open_accounts_analysis;
            if (is_array($analysis) && isset($analysis['utilization_ratio_grid']['revolving'])) {
                $utilization = (float) $analysis['utilization_ratio_grid']['revolving'];
            } elseif ($borrower->creditProfile->credit_utilization !== null) {
                $utilization = (float) $borrower->creditProfile->credit_utilization;
            } elseif ($borrower->creditProfile->open_accounts_utilization_ratio !== null) {
                $utilization = (float) $borrower->creditProfile->open_accounts_utilization_ratio;
            }
        }

        if ($utilization === null) {
            $utilization = 0;
        }

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'utilization')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'utilization')
            ->orderBy('sort_order')
            ->get();

        $value = 0;
        foreach ($tiers as $tier) {
            $minMatched = ($tier->min_value === null || $utilization >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $utilization <= (float) $tier->max_value);

            if ($minMatched && $maxMatched) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $utilization,
            'status' => null
        ];
    }

    private function getTimeInBusinessSubscore(Borrower $borrower)
    {
        $months = $borrower->business_start_date
            ? Carbon::parse($borrower->business_start_date)->diffInMonths()
            : 0;

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'time_in_business')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'time_in_business')
            ->orderBy('sort_order')
            ->get();

        $value = 0;
        $status = null;
        foreach ($tiers as $tier) {
            $minMatched = ($tier->min_value === null || $months >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $months <= (float) $tier->max_value);

            if ($minMatched && $maxMatched) {
                $value = (float) $tier->numeric_value;
                $status = $tier->label;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $months > 0 ? floor($months) . ' months+' : 'New business',
            'status' => $status
        ];
    }

    private function getRevenueStrengthSubscore(Borrower $borrower)
    {
        $annualRevenue = $borrower->annual_revenue !== null
            ? (float) $borrower->annual_revenue
            : 0;

        $months = $borrower->business_start_date
            ? Carbon::parse($borrower->business_start_date)->diffInMonths()
            : 0;

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'revenue_strength')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'revenue_strength')
            ->orderBy('sort_order')
            ->get();

        $status = null;

        if ((!$annualRevenue || $annualRevenue == null || $annualRevenue == 0) && $months <= 24) {
            $value = 55;
        } else {
            $value = 0;
            foreach ($tiers as $tier) {
                $minMatched = ($tier->min_value === null || $annualRevenue >= (float) $tier->min_value);
                $maxMatched = ($tier->max_value === null || $annualRevenue <= (float) $tier->max_value);

                if ($minMatched && $maxMatched) {
                    $value = (float) $tier->numeric_value;
                    $status = $tier->label;
                    break;
                }
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $status,
            'status' => $status
        ];
    }


    private function getTradelineDepthSubscore(Borrower $borrower)
    {
        if (!$borrower->creditProfile) {
            return [
                'score' => 0,
                'value' => null,
            ];
        }

        $openTrades = $borrower->creditProfile->trades->filter(function ($trade) {
            return strtoupper($trade->status ?? '') === 'OPEN';
        });

        $openAccounts = $openTrades->count();

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'tradeline_depth')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'tradeline_depth')
            ->orderBy('sort_order')
            ->get();

        $value = 0;
        $status = null;
        foreach ($tiers as $tier) {
            if ($tier->conditions) {
                $conditions = $tier->conditions;
                $rules = $conditions;
                $results = [];

                foreach ($rules as $rule) {
                    $field = array_key_first($rule);
                    $val = $rule[$field];

                    if (!is_array($val) || !isset($val['operator'], $val['value'])) {
                        continue;
                    }

                    $operator = $val['operator'];
                    $targetValue = $val['value'];

                    if ($field === 'open_accounts') {
                        $borrowerValue = $openAccounts;
                    } else {
                        $borrowerValue = 0;
                    }

                    $results[] = $this->compareValues($borrowerValue, $operator, $targetValue);
                }

                $matched = !in_array(false, $results, true);

                if ($matched) {
                    $value = (float) $tier->numeric_value;
                    $status = $tier->label;
                    break;
                }
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $status,
            'status' => $status
        ];
    }

    private function compareValues($value, string $operator, $target): bool
    {
        if ($value === null)
            return false;

        return match ($operator) {
            '>=' => $value >= $target,
            '<=' => $value <= $target,
            '>' => $value > $target,
            '<' => $value < $target,
            '=' => $value == $target,
            '!=' => $value != $target,
            'between' => is_array($target) && count($target) === 2 && $value >= $target[0] && $value <= $target[1],
            default => false,
        };
    }

    private function getBankingRelationshipsSubscore(Borrower $borrower)
    {
        $allAccounts = $borrower->bankAccounts;
        $totalAccounts = $allAccounts->count();

        $accountAges = $allAccounts->map(function ($account) {
            return $account->account_open_date
                ? Carbon::parse($account->account_open_date)->diffInMonths()
                : 0;
        });

        $subScore = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'banking_relationships')
            ->value('numeric_value');

        $subScoreWeight = $subScore !== null ? (float) $subScore : 0;

        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', 'banking_relationships')
            ->orderBy('sort_order')
            ->get();

        $value = 0;
        $message = 'No banking relationship';
        $status = null;

        foreach ($tiers as $tier) {
            if (!$tier->conditions) {
                continue;
            }

            $rules = $tier->conditions;

            // Extract meta flags and rule definitions
            $each = false;
            $accountsRule = null;
            $checkingMonthsRule = null;

            foreach ($rules as $rule) {
                $field = array_key_first($rule);

                if ($field === 'each') {
                    $each = (bool) $rule[$field];
                } elseif ($field === 'accounts') {
                    $accountsRule = $rule[$field]; // ['operator' => '>=', 'value' => 2]
                } elseif ($field === 'checking_months') {
                    $checkingMonthsRule = $rule[$field]; // ['operator' => '>', 'value' => 12]
                }
            }

            $passed = false;

            // Case 1: accounts = 0 (no accounts expected)
            if ($accountsRule && (int) $accountsRule['value'] === 0 && $accountsRule['operator'] === '=') {
                $passed = $totalAccounts === 0;

                // Case 2: each = true — minimum N accounts AND all of those N must satisfy checking_months
            } elseif ($each && $accountsRule && $checkingMonthsRule) {
                $requiredCount = (int) $accountsRule['value'];
                $monthsTarget = $checkingMonthsRule['value'];
                $monthsOperator = $checkingMonthsRule['operator'];

                // Count how many accounts satisfy the checking_months condition
                $qualifyingCount = $accountAges->filter(function ($age) use ($monthsOperator, $monthsTarget) {
                    return $this->compareValues($age, $monthsOperator, $monthsTarget);
                })->count();

                // All required accounts must meet the age condition
                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $requiredCount)
                    && $qualifyingCount >= $requiredCount;

                // Case 3: each = false + checking_months exists — min 1 account must satisfy checking_months
            } elseif (!$each && $accountsRule && $checkingMonthsRule) {
                $monthsTarget = $checkingMonthsRule['value'];
                $monthsOperator = $checkingMonthsRule['operator'];

                $anyQualifies = $accountAges->contains(function ($age) use ($monthsOperator, $monthsTarget) {
                    return $this->compareValues($age, $monthsOperator, $monthsTarget);
                });

                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $accountsRule['value'])
                    && $anyQualifies;

                // Case 4: no checking_months — just check account count
            } elseif ($accountsRule && !$checkingMonthsRule) {
                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $accountsRule['value']);
            }

            if ($passed) {
                $value = (float) $tier->numeric_value;
                $status = $tier->label;

                if ($each && $checkingMonthsRule) {
                    $requiredCount = (int) $accountsRule['value'];
                    $message = sprintf(
                        '%d accounts, each older than %d months',
                        $requiredCount,
                        $checkingMonthsRule['value']
                    );
                } elseif (!$each && $checkingMonthsRule) {
                    $maxAge = $accountAges->max() ?: 0;
                    $message = sprintf(
                        '%d accounts, oldest relationship %d months',
                        $totalAccounts,
                        $maxAge
                    );
                } else {
                    $message = sprintf('%d account(s)', $totalAccounts);
                }

                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $message,
            'status' => $status,
        ];
    }
}
