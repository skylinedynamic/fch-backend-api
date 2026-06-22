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
     * Accepts an optional pre-loaded config collection (from ScoreConfigurationCache)
     * to avoid firing per-factor queries. When $configs is null the service falls back
     * to loading only what it needs — backward-compatible with direct callers.
     *
     * @param Borrower        $borrower
     * @param Collection|null $configs  All ScoreConfiguration rows (pre-loaded)
     * @return array
     */
    public function calculate(Borrower $borrower, ?Collection $configs = null): array
    {
        // Load all funding_readiness configs in a single query when not pre-supplied
        if ($configs === null) {
            $configs = ScoreConfiguration::where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)->get();
        } else {
            // Filter to only funding_readiness rows from the full collection
            $configs = $configs->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)->values();
        }

        $weights = $configs->where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)->keyBy('factor_slug');
        $tiers = $configs->where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)->groupBy('factor_slug');
        $bands = $configs->where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)->sortBy('sort_order')->values();

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
        $configs = ScoreConfiguration::where('model_slug', 'funding_readiness')->get();
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
        $months = $borrower->business_start_date
            ? Carbon::parse($borrower->business_start_date)->diffInMonths()
            : 0;

        $status = null;

        if ((!$annualRevenue || $annualRevenue == 0) && $months <= 24) {
            $value = 55;
        } else {
            $value = 0;
            foreach ($tiers as $tier) {
                if (
                    ($tier->min_value === null || $annualRevenue >= (float) $tier->min_value)
                    && ($tier->max_value === null || $annualRevenue <= (float) $tier->max_value)
                ) {
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
