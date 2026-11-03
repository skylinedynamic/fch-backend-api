<?php

namespace App\Services\FCH\Common\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\LenderProductCategory;
use Carbon\Carbon;
use Exception;

/**
 * The lenders that fund a borrower's requested product categories, scored
 * against what each lender requires (FICO, business credit, revenue, time in
 * business, deposits) - the Lender Information table on the funding request and
 * funding application details pages.
 *
 * Moved unchanged out of FundingRequest::getMatchedLendersAttribute() so both
 * pages score the same way; a funding request matches on its one funding type,
 * an application without a request on its preferred and additional ones.
 */
class LenderMatchService
{
    /**
     * @param  array<int, string|null>  $categoryNames  product category names, e.g. ["Term Loan"]
     * @return array<int, array<string, mixed>> best match first; a lender in several categories once, at its best
     */
    public function match(?Borrower $borrower, array $categoryNames): array
    {
        $categoryNames = array_values(array_unique(array_filter($categoryNames, fn ($name) => filled($name))));
        if (! $borrower || $categoryNames === []) {
            return [];
        }

        $matched = [];
        foreach ($categoryNames as $categoryName) {
            foreach ($this->matchCategory($borrower, $categoryName) as $lender) {
                $best = $matched[$lender['id']] ?? null;
                if (! $best || $lender['score'] > $best['score']) {
                    $matched[$lender['id']] = $lender;
                }
            }
        }

        $matched = array_values($matched);
        usort($matched, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $matched;
    }

    /** @return array<int, array<string, mixed>> */
    private function matchCategory(Borrower $borrower, string $categoryName): array
    {
        $category = LenderProductCategory::where('name', $categoryName)->first();
        if (! $category) {
            return [];
        }

        $visibilities = LenderVisibility::with('lender')
            ->where('lender_product_category_id', $category->id)
            ->get()
            ->unique('lender_id');

        $matchedLenders = [];

        // calculate TIB
        $tibMonths = 0;
        if ($borrower->business_start_date) {
            try {
                $tibMonths = Carbon::parse($borrower->business_start_date)->diffInMonths(Carbon::now());
            } catch (Exception $e) {
            }
        } elseif ($borrower->years_in_business) {
            if (stripos($borrower->years_in_business, 'month') !== false) {
                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                if (isset($matches[1])) {
                    $tibMonths = (int) $matches[1];
                }
            } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                if (isset($matches[1])) {
                    $tibMonths = (int) $matches[1] * 12;
                }
            }
        }

        $borrowerFico = (int) $borrower->fico_score;
        $borrowerBusinessCredit = (int) $borrower->paydex_score;
        $borrowerRevenue = (float) $borrower->monthly_revenue;
        $borrowerDeposits = (float) $borrower->average_monthly_deposits;

        foreach ($visibilities as $visibility) {
            if (! $visibility->lender) {
                continue;
            }

            $checks = [
                'fico' => false,
                'business_credit' => false,
                'revenue' => false,
                'tib' => false,
                'deposit' => false,
            ];
            $totalCriteria = 0;
            $metCriteria = 0;

            foreach ([
                'fico' => [$visibility->min_fico, $borrowerFico],
                'business_credit' => [$visibility->min_business_credit_score, $borrowerBusinessCredit],
                'revenue' => [$visibility->min_monthly_revenue, $borrowerRevenue],
                'tib' => [$visibility->min_tib_months, $tibMonths],
                'deposit' => [$visibility->min_monthly_deposits, $borrowerDeposits],
            ] as $check => [$minimum, $value]) {
                if (is_null($minimum)) {
                    $checks[$check] = true;

                    continue;
                }

                $totalCriteria++;
                if ($value >= $minimum) {
                    $checks[$check] = true;
                    $metCriteria++;
                }
            }

            $score = $totalCriteria > 0 ? round(($metCriteria / $totalCriteria) * 100) : 100;
            if ($totalCriteria == 0) {
                $matchString = 'Perfect Match';
                $matchHex = '#16a34a';
            } elseif ($score >= 80) {
                $matchString = 'Excellent Match';
                $matchHex = '#16a34a';
            } elseif ($score >= 50) {
                $matchString = 'Good Match';
                $matchHex = '#d97706';
            } else {
                $matchString = 'Low Match';
                $matchHex = '#dc2626';
            }

            $matchedLenders[] = [
                'id' => $visibility->lender->id,
                'name' => $visibility->lender->name,
                'phone' => $visibility->lender->contact_phone,
                'email' => $visibility->lender->contact_email,
                'product_category' => $category->name,
                'requirements' => [
                    'min_fico' => $visibility->min_fico,
                    'min_business_credit_score' => $visibility->min_business_credit_score,
                    'min_revenue' => $visibility->min_monthly_revenue,
                    'min_tib' => $visibility->min_tib_months,
                    'min_deposit' => $visibility->min_monthly_deposits,
                ],
                'checks' => $checks,
                'score' => $score,
                'score_text' => $matchString,
                'score_hex' => $matchHex,
                'matches_fraction' => "{$metCriteria}/" . ($totalCriteria ?: 4),
            ];
        }

        return $matchedLenders;
    }
}
