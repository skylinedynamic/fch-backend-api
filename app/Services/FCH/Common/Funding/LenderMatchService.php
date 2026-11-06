<?php

namespace App\Services\FCH\Common\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Support\Funding\FundingTypeName;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;

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
     * @param  array<int, string|null>  $categoryNames  product category names, the preferred one first,
     *                                                   e.g. ["Term Loan", "Business Line of Credit"]
     * @return array<int, array<string, mixed>> the highest match % first; on an equal %, a lender of the
     *                                          preferred (first) category first. A lender in several
     *                                          categories is listed once, at its best.
     */
    public function match(?Borrower $borrower, array $categoryNames): array
    {
        $categoryNames = array_values(array_unique(array_filter($categoryNames, fn ($name) => filled($name))));
        if (! $borrower || $categoryNames === []) {
            return [];
        }

        $preferredKey = $this->categoryKey($categoryNames[0]);
        $matched = [];
        $inPreferred = [];
        foreach ($this->categoriesFor($categoryNames) as $category) {
            $isPreferred = $this->categoryKey($category->name) === $preferredKey;
            foreach ($this->matchCategory($borrower, $category) as $lender) {
                $inPreferred[$lender['id']] = ($inPreferred[$lender['id']] ?? false) || $isPreferred;
                $best = $matched[$lender['id']] ?? null;
                if (! $best || $lender['score'] > $best['score']) {
                    $matched[$lender['id']] = $lender;
                }
            }
        }

        $matched = array_values($matched);
        usort($matched, fn ($a, $b) => [$b['score'], $inPreferred[$b['id']]] <=> [$a['score'], $inPreferred[$a['id']]]);

        return $matched;
    }

    /**
     * The lender product categories (Lenders > Product Categories) for funding
     * type names. An older name is worded differently from the categories, so a
     * name matches a category with the same words (FundingTypeName):
     * "Refinance / Debt Consolidation" is "Debt Consolidation / Refinance".
     *
     * @param  array<int, string>  $names
     * @return Collection<int, LenderProductCategory>
     */
    private function categoriesFor(array $names): Collection
    {
        $wanted = array_filter(array_map(fn (string $name) => $this->categoryKey($name), $names));

        return LenderProductCategory::all()
            ->filter(fn (LenderProductCategory $category) => in_array($this->categoryKey($category->name), $wanted, true))
            ->values();
    }

    private function categoryKey(string $name): string
    {
        return FundingTypeName::key($name);
    }

    /** @return array<int, array<string, mixed>> */
    private function matchCategory(Borrower $borrower, LenderProductCategory $category): array
    {
        // An inactive lender is not offered: not matched, so not in the
        // Assign list or the lender filter built from it (R2 BUG-020).
        $visibilities = LenderVisibility::with('lender')
            ->where('lender_product_category_id', $category->id)
            ->whereHas('lender', fn ($q) => $q->where('status', Lender::STATUS_ACTIVE))
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
            // Only an Active lender is offered (an Inactive one cannot be assigned either).
            if (! $visibility->lender || $visibility->lender->status !== Lender::STATUS_ACTIVE) {
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
                'legal_name' => $visibility->lender->legal_name,
                // "Lender Name - Legal Name": what the screens show (R2 BUG-008).
                'display_name' => $visibility->lender->displayName(),
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
