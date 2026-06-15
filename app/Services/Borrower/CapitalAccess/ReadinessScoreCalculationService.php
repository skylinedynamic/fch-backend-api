<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use Carbon\Carbon;

class ReadinessScoreCalculationService
{
    /**
     * Calculate the Funding Readiness Score for the borrower.
     */
    public function calculate(Borrower $borrower): int
    {
        // 1. Fetch weights for the model 'funding_readiness'
        $weights = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', 'funding_readiness')
            ->get();

        $totalScore = 0.0;
        $totalWeight = 0.0;

        foreach ($weights as $weightRow) {
            $factorSlug = $weightRow->factor_slug;
            $weight = (float) $weightRow->numeric_value;

            // Get subscore for this factor
            $subscore = $this->getSubscoreForFactor($factorSlug, $borrower);

            // Add weighted subscore
            $totalScore += $subscore * ($weight / 100.0);
            $totalWeight += $weight;
        }

        // Normalize if total weight doesn't sum to exactly 100
        if ($totalWeight > 0 && abs($totalWeight - 100.0) > 0.01) {
            $totalScore = ($totalScore / $totalWeight) * 100.0;
        }

        return (int) round($totalScore);
    }

    /**
     * Get the subscore for a specific factor based on database rules.
     */
    public function getSubscoreForFactor(string $factorSlug, Borrower $borrower): float
    {
        $tiers = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', 'funding_readiness')
            ->where('factor_slug', $factorSlug)
            ->orderBy('sort_order', 'asc')
            ->get();

        foreach ($tiers as $tier) {
            if ($tier->conditions) {
                if ($this->evaluateCompoundConditions($tier->conditions, $borrower)) {
                    return (float) $tier->numeric_value;
                }
            } else {
                $value = $this->getSimpleFactorValue($factorSlug, $borrower);

                if ($value !== null) {
                    $minMatched = ($tier->min_value === null || $value >= (float) $tier->min_value);
                    $maxMatched = ($tier->max_value === null || $value <= (float) $tier->max_value);
                    if ($minMatched && $maxMatched) {
                        return (float) $tier->numeric_value;
                    }
                } else {
                    // Fallback tier (typically last in order, like Weak or Thin)
                    return (float) $tier->numeric_value;
                }
            }
        }

        return 0.0;
    }

    /**
     * Get simple numerical/scalar values for factors that do not use complex compound rules.
     */
    private function getSimpleFactorValue(string $factorSlug, Borrower $borrower)
    {
        switch ($factorSlug) {
            case 'fico':
                // Prefer the credit profile FICO score; fall back to borrower.fico_score
                if ($borrower->creditProfile && $borrower->creditProfile->fico_score !== null) {
                    return (int) $borrower->creditProfile->fico_score;
                }
                return $borrower->fico_score !== null ? (int) $borrower->fico_score : null;

            case 'utilization':
                // Try to get revolving utilization from open_accounts_analysis
                if ($borrower->creditProfile) {
                    $analysis = $borrower->creditProfile->open_accounts_analysis;
                    if (is_array($analysis) && isset($analysis['utilization_ratio_grid']['revolving'])) {
                        return (float) $analysis['utilization_ratio_grid']['revolving'];
                    }

                    // Fallback: Use the direct credit_utilization column
                    if ($borrower->creditProfile->credit_utilization !== null) {
                        return (float) $borrower->creditProfile->credit_utilization;
                    }
                    // Fallback: try open_accounts_utilization_ratio
                    if ($borrower->creditProfile->open_accounts_utilization_ratio !== null) {
                        return (float) $borrower->creditProfile->open_accounts_utilization_ratio;
                    }
                }
                return null;

            case 'time_in_business':
                return $borrower->business_start_date
                    ? Carbon::parse($borrower->business_start_date)->diffInMonths()
                    : 0;

            case 'revenue_strength':
                // Monthly revenue from the borrower record
                return $borrower->monthly_revenue !== null
                    ? (float) $borrower->monthly_revenue
                    : null;

            case 'tradeline_depth':
                // Total open accounts from the credit profile
                if ($borrower->creditProfile) {
                    return $borrower->creditProfile->all_accounts_open !== null
                        ? (int) $borrower->creditProfile->all_accounts_open
                        : null;
                }
                return null;

            default:
                return null;
        }
    }


    /**
     * Evaluate compound OR (any) or AND (all) conditions for a tier.
     */
    private function evaluateCompoundConditions(array $conditions, Borrower $borrower): bool
    {
        $type = isset($conditions['any']) ? 'any' : (isset($conditions['all']) ? 'all' : null);
        if (!$type) {
            return false;
        }

        $rules = $conditions[$type];
        $results = [];

        foreach ($rules as $rule) {
            $field = $rule['field'];
            $operator = $rule['operator'];
            $targetValue = $rule['value'];

            $borrowerValue = $this->getFieldValue($field, $borrower);
            $results[] = $this->compareValues($borrowerValue, $operator, $targetValue);
        }

        if ($type === 'any') {
            return in_array(true, $results, true);
        } else {
            return !in_array(false, $results, true);
        }
    }

    /**
     * Map logical field names used in conditions to actual model properties or calculations.
     */
    private function getFieldValue(string $field, Borrower $borrower)
    {
        switch ($field) {
            case 'business_revenue_monthly':
                return $borrower->monthly_revenue !== null ? (float) $borrower->monthly_revenue : null;
            case 'personal_income_annual':
                return $borrower->annual_revenue !== null ? (float) $borrower->annual_revenue : null;
            case 'open_accounts':
                if ($borrower->creditProfile) {
                    return $borrower->creditProfile->all_accounts_open !== null
                        ? (int) $borrower->creditProfile->all_accounts_open
                        : null;
                }
                return null;
            case 'high_limit_accounts':
                if ($borrower->creditProfile) {
                    return $borrower->creditProfile->trades()
                        ->where(function($q) {
                            $q->where('limit', '>=', 5000)
                              ->orWhere('high_credit', '>=', 5000);
                        })->count();
                }
                return 0;
            case 'avg_high_limit':
                if ($borrower->creditProfile) {
                    return (float) ($borrower->creditProfile->trades()->avg('limit') ?? ($borrower->creditProfile->trades()->avg('high_credit') ?? 0));
                }
                return 0.0;
            case 'checking_years':
                $additional = $borrower->additional_data ?? [];
                if (isset($additional['checking_years'])) {
                    return (float) $additional['checking_years'];
                }
                if (isset($additional['checking_months'])) {
                    return (float) ($additional['checking_months'] / 12);
                }
                return 0.0;
            case 'checking_months':
                $additional = $borrower->additional_data ?? [];
                if (isset($additional['checking_months'])) {
                    return (float) $additional['checking_months'];
                }
                if (isset($additional['checking_years'])) {
                    return (float) ($additional['checking_years'] * 12);
                }
                return 0.0;
            case 'has_savings_or_loc':
                $additional = $borrower->additional_data ?? [];
                return isset($additional['has_savings_or_loc']) ? filter_var($additional['has_savings_or_loc'], FILTER_VALIDATE_BOOLEAN) : false;
            case 'has_regular_deposits':
                $additional = $borrower->additional_data ?? [];
                return isset($additional['has_regular_deposits']) ? filter_var($additional['has_regular_deposits'], FILTER_VALIDATE_BOOLEAN) : false;
            default:
                return null;
        }
    }

    /**
     * Compare field value against target condition using operator.
     */
    private function compareValues($value, string $operator, $target): bool
    {
        if ($value === null) {
            return false;
        }

        switch ($operator) {
            case '>=':
                return $value >= $target;
            case '<=':
                return $value <= $target;
            case '>':
                return $value > $target;
            case '<':
                return $value < $target;
            case '=':
                return $value == $target;
            case '!=':
                return $value != $target;
            case 'between':
                if (is_array($target) && count($target) === 2) {
                    return $value >= $target[0] && $value <= $target[1];
                }
                return false;
            default:
                return false;
        }
    }
}
