<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReadinessScoreCalculationService
{
    /**
     * Calculate the funding readiness score.
     *
     * @param Borrower        $borrower
     * @return array
     */
    public function calculate(Borrower $borrower): array
    {
        $cache = app(ScoreConfigurationCache::class);
        $weights = $cache->getWeightsFor(ScoreConfiguration::MODEL_FUNDING_READINESS);
        $tiers = $cache->getTiersFor(ScoreConfiguration::MODEL_FUNDING_READINESS);
        $bands = $cache->getReadinessBands();

        $factors = [
            ScoreConfiguration::FACTOR_FICO => ['label' => 'Personal Credit', 'slug' => ScoreConfiguration::FACTOR_FICO],
            ScoreConfiguration::FACTOR_UTILIZATION => ['label' => 'Utilization', 'slug' => ScoreConfiguration::FACTOR_UTILIZATION],
            ScoreConfiguration::FACTOR_TIME_IN_BUSINESS => ['label' => 'Time in Business', 'slug' => ScoreConfiguration::FACTOR_TIME_IN_BUSINESS],
            ScoreConfiguration::FACTOR_REVENUE_STRENGTH => ['label' => 'Revenue Strength', 'slug' => ScoreConfiguration::FACTOR_REVENUE_STRENGTH],
            ScoreConfiguration::FACTOR_TRADELINE_DEPTH => ['label' => 'Tradeline Depth', 'slug' => ScoreConfiguration::FACTOR_TRADELINE_DEPTH],
            ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS => ['label' => 'Banking Relationships', 'slug' => ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS],
        ];

        $factorScores = [];
        $totalScore = 0;

        foreach ($factors as $key => $info) {
            $method = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Subscore';
            $weight = (float) ($weights->get($key)?->numeric_value ?? 0);
            $factorTiers = $tiers->get($key, collect());
            $weightedScore = $this->$method($borrower, $weight, $factorTiers);
            $rawScore = $weight > 0 ? round($weightedScore['score'] / ($weight / 100), 2) : 0;

            $factorScores[] = [
                'slug' => $info['slug'],
                'label' => $info['label'],
                'value' => $weightedScore['value'],
                'weighted_score' => round($weightedScore['score'], 2),
                'raw_score' => $rawScore,
                'status' => $weightedScore['status'] ?? null,
            ];
            $totalScore += $weightedScore['score'];
        }

        $totalScore = (int) round($totalScore) > 100 ? 100 : (int) round($totalScore);

        $band = $bands->first(fn($b) => $b->min_value === null || $totalScore >= (float) $b->min_value);

        return [
            'total_score' => $totalScore,
            'score_label' => $band?->label ?? 'Not Ready',
            'score_label_color' => $band?->color ?? '#6c757d',
            'factors' => $factorScores,
        ];
    }

    /**
     * Calculate subscore for a single factor.
     * Kept for backward compatibility with any direct callers.
     */
    public function getSubscoreForFactor(string $slug, Borrower $borrower): float
    {
        $configs = ScoreConfiguration::where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)->get();
        $weight = (float) ($configs->where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)->where('factor_slug', $slug)->first()?->numeric_value ?? 0);
        $factorTiers = $configs->where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)->where('factor_slug', $slug)->values();

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

        $result = $this->{$methodMap[$slug]}($borrower, $weight, $factorTiers);
        $score = is_array($result) ? $result['score'] : (float) $result;
        return $score / ($weight > 0 ? ($weight / 100) : 1);
    }

    // =========================================================================
    // Factor subscores — all now accept $weight and $tiers from the caller
    // =========================================================================

    private function getFicoSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        $fico = null;
        if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
            $fico = (int) $borrower->creditProfile->fico_score;
        } elseif ($borrower->fico_score !== null) {
            $fico = (int) $borrower->fico_score;
        }
        $fico = $fico ?? 0;

        $value = 0;
        foreach ($tiers as $tier) {
            if (
                ($tier->min_value === null || $fico >= (float) $tier->min_value)
                && ($tier->max_value === null || $fico <= (float) $tier->max_value)
            ) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $fico,
            'status' => null,
        ];
    }

    private function getUtilizationSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        $utilization = null;
        if ($borrower->creditProfile) {
            $analysis = $borrower->creditProfile->open_accounts_analysis;
            if (is_array($analysis) && isset($analysis['utilization_ratio_grid']['revolving'])) {
                $utilization = (float) $analysis['utilization_ratio_grid']['revolving'];
            }
        }
        $utilization = $utilization ?? 0;

        $value = 0;
        foreach ($tiers as $tier) {
            if (
                ($tier->min_value === null || $utilization >= (float) $tier->min_value)
                && ($tier->max_value === null || $utilization <= (float) $tier->max_value)
            ) {
                $value = (float) $tier->numeric_value;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $utilization,
            'status' => null,
        ];
    }

    private function getTimeInBusinessSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        $months = $borrower->business_start_date
            ? Carbon::parse($borrower->business_start_date)->diffInMonths()
            : 0;

        $value = 0;
        $status = null;
        foreach ($tiers as $tier) {
            if (
                ($tier->min_value === null || $months >= (float) $tier->min_value)
                && ($tier->max_value === null || $months <= (float) $tier->max_value)
            ) {
                $value = (float) $tier->numeric_value;
                $status = $tier->label;
                break;
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $months > 0 ? floor($months) . ' months+' : 'New business',
            'status' => $status,
        ];
    }

    private function getRevenueStrengthSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        $annualRevenue = $borrower->annual_revenue !== null ? (float) $borrower->annual_revenue : 0;
        $personalIncome = $borrower->annual_personal_income !== null ? (float) $borrower->annual_personal_income : 0;

        $months = $borrower->business_start_date
            ? Carbon::parse($borrower->business_start_date)->diffInMonths()
            : 0;

        $exceptionRevenue = 0.0;
        $exceptionMonths = 24;
        $startupSubscore = 55.0;

        $startupConfig = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)
            ->where('factor_slug', ScoreConfiguration::FACTOR_STARTUP_REVENUE_STRENGTH)
            ->first();

        if ($startupConfig) {
            $startupSubscore = (float) $startupConfig->numeric_value;
            $conditions = $startupConfig->conditions;
            if (is_string($conditions)) {
                $conditions = json_decode($conditions, true);
            }
            if (is_array($conditions)) {
                foreach ($conditions as $cond) {
                    if (isset($cond['business_revenue'])) {
                        $exceptionRevenue = (float) ($cond['business_revenue']['value'] ?? 0);
                    }
                    if (isset($cond['business_age'])) {
                        $exceptionMonths = (int) ($cond['business_age']['value'] ?? 0);
                    }
                }
            }
        }

        // 1. Calculate Business Revenue Subscore
        $busSubscore = 0;
        $busStatus = null;
        if ($annualRevenue <= $exceptionRevenue && $months <= $exceptionMonths) {
            $busSubscore = $startupSubscore;
            $busStatus = 'Startup';
        } else {
            foreach ($tiers as $tier) {
                $conditions = $tier->conditions;
                if (is_string($conditions)) {
                    $conditions = json_decode($conditions, true);
                }

                if (empty($conditions)) {
                    $comparisonValue = $annualRevenue;
                    if (
                        ($tier->min_value === null || $comparisonValue >= (float) $tier->min_value)
                        && ($tier->max_value === null || $comparisonValue <= (float) $tier->max_value)
                    ) {
                        $busSubscore = (float) $tier->numeric_value;
                        $busStatus = $tier->label;
                        break;
                    }
                    continue;
                }

                $busVal = null;
                $busOp = '>=';
                foreach ($conditions as $cond) {
                    if (isset($cond['business_revenue'])) {
                        $busVal = (float) ($cond['business_revenue']['value'] ?? 0);
                        $busOp = $cond['business_revenue']['operator'] ?? '>=';
                        break;
                    }
                }

                $busMatch = false;
                if ($busVal !== null) {
                    if ($busOp === '>=') {
                        $busMatch = $annualRevenue >= $busVal;
                    } else if ($busOp === '<') {
                        $busMatch = $annualRevenue < $busVal;
                    }
                }

                if ($busMatch) {
                    $busSubscore = (float) $tier->numeric_value;
                    $busStatus = $tier->label;
                    break;
                }
            }
        }

        // 2. Calculate Personal Income Subscore
        $persSubscore = 0;
        $persStatus = null;
        if ($personalIncome <= $exceptionRevenue && $months <= $exceptionMonths) {
            $persSubscore = $startupSubscore;
            $persStatus = 'Startup';
        } else {
            foreach ($tiers as $tier) {
                $conditions = $tier->conditions;
                if (is_string($conditions)) {
                    $conditions = json_decode($conditions, true);
                }

                if (empty($conditions)) {
                    $comparisonValue = $personalIncome;
                    if (
                        ($tier->min_value === null || $comparisonValue >= (float) $tier->min_value)
                        && ($tier->max_value === null || $comparisonValue <= (float) $tier->max_value)
                    ) {
                        $persSubscore = (float) $tier->numeric_value;
                        $persStatus = $tier->label;
                        break;
                    }
                    continue;
                }

                $persVal = null;
                $persOp = '>=';
                foreach ($conditions as $cond) {
                    if (isset($cond['personal_income'])) {
                        $persVal = (float) ($cond['personal_income']['value'] ?? 0);
                        $persOp = $cond['personal_income']['operator'] ?? '>=';
                        break;
                    }
                }

                $persMatch = false;
                if ($persVal !== null) {
                    if ($persOp === '>=') {
                        $persMatch = $personalIncome >= $persVal;
                    } else if ($persOp === '<') {
                        $persMatch = $personalIncome < $persVal;
                    }
                }

                if ($persMatch) {
                    $persSubscore = (float) $tier->numeric_value;
                    $persStatus = $tier->label;
                    break;
                }
            }
        }

        // 3. Select the greater subscore
        if ($busSubscore >= $persSubscore) {
            $value = $busSubscore;
            $status = $busStatus;
        } else {
            $value = $persSubscore;
            $status = $persStatus;
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $status,
            'status' => $status,
        ];
    }

    private function getTradelineDepthSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        if (!$borrower->creditProfile) {
            return ['score' => 0, 'value' => null, 'status' => null];
        }

        $openAccounts = $borrower->creditProfile->trades->filter(
            fn($trade) => strtoupper($trade->status ?? '') === 'OPEN'
        )->count();

        $value = 0;
        $status = null;

        foreach ($tiers as $tier) {
            if ($tier->conditions) {
                $results = [];
                foreach ($tier->conditions as $rule) {
                    $field = array_key_first($rule);
                    $val = $rule[$field];
                    if (!is_array($val) || !isset($val['operator'], $val['value'])) {
                        continue;
                    }
                    $borrowerValue = $field === 'open_accounts' ? $openAccounts : 0;
                    $results[] = $this->compareValues($borrowerValue, $val['operator'], $val['value']);
                }

                if (!in_array(false, $results, true)) {
                    $value = (float) $tier->numeric_value;
                    $status = $tier->label;
                    break;
                }
            }
        }

        return [
            'score' => $value * ($subScoreWeight / 100),
            'value' => $status,
            'status' => $status,
        ];
    }

    private function getBankingRelationshipsSubscore(Borrower $borrower, float $subScoreWeight, Collection $tiers): array
    {
        $allAccounts = $borrower->bankAccounts;
        $totalAccounts = $allAccounts->count();

        $accountAges = $allAccounts->map(
            fn($a) =>
            $a->account_open_date ? Carbon::parse($a->account_open_date)->diffInMonths() : 0
        );

        $value = 0;
        $message = 'No banking relationship';
        $status = null;

        foreach ($tiers as $tier) {
            if (!$tier->conditions) {
                continue;
            }

            $each = false;
            $accountsRule = null;
            $checkingMonthsRule = null;

            foreach ($tier->conditions as $rule) {
                $field = array_key_first($rule);
                if ($field === 'each') {
                    $each = (bool) $rule[$field];
                } elseif ($field === 'accounts') {
                    $accountsRule = $rule[$field];
                } elseif ($field === 'checking_months') {
                    $checkingMonthsRule = $rule[$field];
                }
            }

            $passed = false;

            if ($accountsRule && (int) $accountsRule['value'] === 0 && $accountsRule['operator'] === '=') {
                $passed = $totalAccounts === 0;
            } elseif ($each && $accountsRule && $checkingMonthsRule) {
                $requiredCount = (int) $accountsRule['value'];
                $qualifyingCount = $accountAges->filter(
                    fn($age) => $this->compareValues($age, $checkingMonthsRule['operator'], $checkingMonthsRule['value'])
                )->count();
                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $requiredCount)
                    && $qualifyingCount >= $requiredCount;
            } elseif (!$each && $accountsRule && $checkingMonthsRule) {
                $anyQualifies = $accountAges->contains(
                    fn($age) => $this->compareValues($age, $checkingMonthsRule['operator'], $checkingMonthsRule['value'])
                );
                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $accountsRule['value'])
                    && $anyQualifies;
            } elseif ($accountsRule && !$checkingMonthsRule) {
                $passed = $this->compareValues($totalAccounts, $accountsRule['operator'], $accountsRule['value']);
            }

            if ($passed) {
                $value = (float) $tier->numeric_value;
                $status = $tier->label;

                if ($each && $checkingMonthsRule) {
                    $message = sprintf('%d accounts, each older than %d months', (int) $accountsRule['value'], $checkingMonthsRule['value']);
                } elseif (!$each && $checkingMonthsRule) {
                    $message = sprintf('%d accounts, oldest relationship %d months', $totalAccounts, $accountAges->max() ?: 0);
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
}
