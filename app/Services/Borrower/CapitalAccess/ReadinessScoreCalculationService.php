<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use Carbon\Carbon;

class ReadinessScoreCalculationService
{
    public function calculate(Borrower $borrower): int
    {
        $ficoScore = $this->getFicoSubscore($borrower);
        $utilizationScore = $this->getUtilizationSubscore($borrower);
        $timeInBusinessScore = $this->getTimeInBusinessSubscore($borrower);
        $revenueIncomeScore = $this->getRevenueIncomeSubscore($borrower);
        $tradelineDepthScore = $this->getTradelineDepthSubscore($borrower);
        $bankingRelationshipScore = $this->getBankingRelationshipSubscore($borrower);

        $totalScore = $ficoScore
            + $utilizationScore
            + $timeInBusinessScore
            + $revenueIncomeScore
            + $tradelineDepthScore
            + $bankingRelationshipScore;

        return (int) round($totalScore);
    }

    private function getFicoSubscore(Borrower $borrower): float
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


        return $value * ($subScoreWeight / 100);
    }

    private function getUtilizationSubscore(Borrower $borrower): float
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


        return $value * ($subScoreWeight / 100);
    }

    private function getTimeInBusinessSubscore(Borrower $borrower): float
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
        foreach ($tiers as $tier) {
            $minMatched = ($tier->min_value === null || $months >= (float) $tier->min_value);
            $maxMatched = ($tier->max_value === null || $months <= (float) $tier->max_value);

            if ($minMatched && $maxMatched) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return $value * ($subScoreWeight / 100);
    }

    private function getRevenueIncomeSubscore(Borrower $borrower): float
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

        if ((!$annualRevenue || $annualRevenue == null || $annualRevenue == 0) && $months <= 24) {
            $value = 55;
        } else {
            $value = 0;
            foreach ($tiers as $tier) {
                $minMatched = ($tier->min_value === null || $annualRevenue >= (float) $tier->min_value);
                $maxMatched = ($tier->max_value === null || $annualRevenue <= (float) $tier->max_value);

                if ($minMatched && $maxMatched) {
                    $value = (float) $tier->numeric_value;
                    break;
                }
            }
        }

        return $value * ($subScoreWeight / 100);
    }


    private function getTradelineDepthSubscore(Borrower $borrower): float
    {
        if (!$borrower->creditProfile) {
            return 0;
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
        foreach ($tiers as $tier) {
            if ($tier->conditions) {
                $conditions = $tier->conditions;
                $rules = $conditions;
                $results = [];

                foreach ($rules as $rule) {
                    $field = array_key_first($rule);
                    $operator = $rule[$field]['operator'];
                    $targetValue = $rule[$field]['value'];

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
                    break;
                }
            }
        }

        return $value * ($subScoreWeight / 100);
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

    private function getBankingRelationshipSubscore(Borrower $borrower): float
    {
        $allAccounts = $borrower->bankAccounts;
        $totalAccounts = $allAccounts->count();

        $accountAges = $allAccounts->map(function ($account) {
            return $account->account_open_date
                ? Carbon::parse($account->account_open_date)->diffInMonths()
                : 0;
        });

        $minAge = $accountAges->min() ?: 0;
        $maxAge = $accountAges->max() ?: 0;

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
        foreach ($tiers as $tier) {
            if (!$tier->conditions) {
                continue;
            }

            $rules = $tier->conditions;

            $each = false;
            $checkingMonthsValue = null;
            $checkingMonthsOperator = null;
            foreach ($rules as $rule) {
                $field = array_key_first($rule);
                if ($field === 'each') {
                    $each = (bool) $rule[$field];
                }
                if ($field === 'checking_months') {
                    $checkingMonthsValue = $rule[$field]['value'] ?? null;
                    $checkingMonthsOperator = $rule[$field]['operator'] ?? null;
                }
            }

            $results = [];
            foreach ($rules as $rule) {
                $field = array_key_first($rule);
                $val = $rule[$field];

                if (!is_array($val) || !isset($val['operator'], $val['value'])) {
                    continue;
                }

                $operator = $val['operator'];
                $targetValue = $val['value'];

                if ($field === 'accounts') {
                    if ($checkingMonthsValue !== null && $checkingMonthsOperator !== null && !$each) {
                        $borrowerValue = $accountAges->filter(function ($age) use ($checkingMonthsValue, $checkingMonthsOperator) {
                            return $this->compareValues($age, $checkingMonthsOperator, $checkingMonthsValue);
                        })->count();
                    } else {
                        $borrowerValue = $totalAccounts;
                    }
                } elseif ($field === 'checking_months') {
                    $borrowerValue = $each ? $minAge : $maxAge;
                } else {
                    $borrowerValue = 0;
                }

                $results[] = $this->compareValues($borrowerValue, $operator, $targetValue);
            }

            $matched = !in_array(false, $results, true);

            if ($matched) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return $value * ($subScoreWeight / 100);
    }
}
