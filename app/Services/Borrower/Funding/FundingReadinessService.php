<?php

namespace App\Services\Borrower\Funding;

use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\BorrowerDocumentsChecklist;
use App\Models\Borrower\FundingApplication;

class FundingReadinessService
{
    /**
     * Evaluate missing documents for a borrower.
     *
     * @param mixed $fundingOption
     * @param \App\Models\Borrower\Borrower $borrower
     * @return array
     */
    public function getMissingDocuments($fundingOption, $borrower): array
    {
        if (!$borrower || !$fundingOption) {
            return [];
        }

        // If documents are not required at all, nothing is missing
        if (!$fundingOption->require_documents_uploaded) {
            return [];
        }

        $requiredIds = $fundingOption->allowed_documents ?? [];

        // If specific documents are required, check them
        if (!empty($requiredIds)) {
            $uploadedIds = BorrowerDocument::where('borrower_id', $borrower->id)
                ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
                ->pluck('document_type_id')
                ->toArray();

            $missingIds = array_values(array_diff($requiredIds, $uploadedIds));

            if (empty($missingIds)) {
                return [];
            }

            return BorrowerDocType::whereIn('id', $missingIds)->pluck('name')->toArray();
        }

        // If no specific documents are required but documents are required in general,
        // we just need ANY document to be uploaded.
        $hasAny = BorrowerDocument::where('borrower_id', $borrower->id)
            ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
            ->exists();

        return $hasAny ? [] : ['Please upload your required business documents.'];
    }

    /**
     * Evaluate the readiness factors based on the funding option requirements and borrower profile.
     *
     * @param mixed $fundingOption
     * @param \App\Models\Borrower\Borrower|null $borrower
     * @param array $missingDocumentsList
     * @return array
     */
    public function evaluateFactors($fundingOption, $borrower, array $missingDocumentsList = []): array
    {
        $formattedReadinessFactors = [];
        $rawFactors = $fundingOption->readiness_factors;

        if (!is_array($rawFactors)) {
            return $formattedReadinessFactors;
        }

        $statusGood = config('funding.readiness_factors.statuses.good', 'Good');
        $statusStrong = config('funding.readiness_factors.statuses.strong', 'Strong');
        $statusMissing = config('funding.readiness_factors.statuses.missing', 'Missing');

        foreach ($rawFactors as $factor) {
            $label = is_array($factor) && isset($factor['label']) ? $factor['label'] : (is_string($factor) ? $factor : '');
            if (!$label) continue;

            $status = $statusGood; // Default

            if ($borrower) {
                $lowerLabel = strtolower($label);
                
                $lenders = $fundingOption->lenderVisibilities ?? collect();

                if (str_contains($lowerLabel, 'business credit') || $lowerLabel === 'business credit') {
                    $status = $this->evaluateCriteria($borrower->paydex_score, $lenders, 'min_fico', 'buffer', 20);
                } elseif (str_contains($lowerLabel, 'credit')) {
                    $status = $this->evaluateCriteria($borrower->fico_score, $lenders, 'min_fico', 'buffer', config('funding.readiness_factors.personal_credit.strong_buffer', 50));
                } elseif (str_contains($lowerLabel, 'revenue') || str_contains($lowerLabel, 'sales')) {
                    $status = $this->evaluateCriteria($borrower->monthly_revenue, $lenders, 'min_monthly_revenue', 'multiplier', config('funding.readiness_factors.revenue.strong_multiplier', 2.0));
                } elseif (str_contains($lowerLabel, 'time') || str_contains($lowerLabel, 'business')) {
                    $tibMonths = 0;
                    if (stripos($borrower->years_in_business, 'month') !== false) {
                        preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                        if (isset($matches[1])) $tibMonths = (int)$matches[1];
                    } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                        preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                        if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
                    }
                    if (is_null($borrower->years_in_business) || trim($borrower->years_in_business) === '') {
                        $status = $statusMissing;
                    } else {
                        $status = $this->evaluateCriteria($tibMonths, $lenders, 'min_tib_months', 'buffer', config('funding.readiness_factors.time_in_business.strong_buffer_months', 12));
                    }
                } elseif (str_contains($lowerLabel, 'bank') || str_contains($lowerLabel, 'activit')) {
                    $docName = config('funding_qualification.rules.banking_activity.document_name', BorrowerDocType::BANK_STATEMENTS);
                    $docType = BorrowerDocType::where('name', $docName)->first();
                    if ($docType && BorrowerDocument::where('borrower_id', $borrower->id)->where('document_type_id', $docType->id)->whereIn('status', [BorrowerDocument::STATUS_UPLOADED, BorrowerDocument::STATUS_ACCEPTED])->exists()) {
                        $status = $statusGood;
                    } else {
                        $status = $statusMissing;
                    }
                } elseif (str_contains($lowerLabel, 'deposit')) {
                    $status = $this->evaluateCriteria($borrower->monthly_revenue, $lenders, 'min_monthly_deposits', 'multiplier', 1.5);
                } elseif (str_contains($lowerLabel, 'balance')) {
                    $status = $this->evaluateCriteria($borrower->avg_bank_balance, $lenders, 'min_average_daily_balance', 'multiplier', 1.5);
                } elseif (str_contains($lowerLabel, 'document')) {
                    $status = $statusMissing;
                }
            }

            $formattedReadinessFactors[] = [
                'label' => $label,
                'status' => $status
            ];
        }

        return $formattedReadinessFactors;
    }

    /**
     * Helper to evaluate a specific lender criteria against borrower value.
     */
    private function evaluateCriteria($borrowerValue, $lenders, $lenderField, $strongType = 'buffer', $strongValue = 0)
    {
        $statusMissing = config('funding.readiness_factors.statuses.missing', 'Missing');
        $statusGood = config('funding.readiness_factors.statuses.good', 'Good');
        $statusStrong = config('funding.readiness_factors.statuses.strong', 'Strong');

        if (is_null($borrowerValue) || (is_numeric($borrowerValue) && $borrowerValue <= 0 && $lenderField !== 'min_tib_months')) {
            return $statusMissing;
        }

        if (!$lenders || $lenders->isEmpty()) {
            return $statusGood;
        }

        $passCount = 0;
        $strongPassCount = 0;
        $totalLenders = $lenders->count();

        foreach ($lenders as $lender) {
            $lenderValue = $lender->{$lenderField};
            if (is_null($lenderValue)) {
                $passCount++;
                $strongPassCount++;
            } else {
                if ($borrowerValue >= $lenderValue) {
                    $passCount++;
                }
                
                $strongThreshold = $strongType === 'multiplier' 
                    ? $lenderValue * $strongValue 
                    : $lenderValue + $strongValue;
                    
                if ($borrowerValue >= $strongThreshold) {
                    $strongPassCount++;
                }
            }
        }

        if ($passCount < $totalLenders) {
            return $statusMissing;
        } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
            return $statusStrong;
        }

        return $statusGood;
    }
}
