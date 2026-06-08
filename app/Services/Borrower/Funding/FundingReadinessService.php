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
        if (!$borrower) {
            return [];
        }

        $requiredDocTypeIds = BorrowerDocumentsChecklist::where('borrower_id', $borrower->id)->pluck('borrower_doc_types_id');
        $uploadedDocsCount = BorrowerDocument::where('borrower_id', $borrower->id)->count();

        // If the borrower has a specific checklist assigned
        if ($requiredDocTypeIds->count() > 0) {
            $uploadedDocTypeIds = BorrowerDocument::where('borrower_id', $borrower->id)->pluck('document_type_id');
            $missingDocTypeIds = $requiredDocTypeIds->diff($uploadedDocTypeIds);
            
            if ($missingDocTypeIds->count() > 0) {
                return BorrowerDocType::whereIn('id', $missingDocTypeIds)->pluck('name')->toArray();
            }
            return [];
        }

        // If no checklist exists but the borrower has uploaded 0 documents
        if ($uploadedDocsCount === 0) {
            // Fallback to the funding option's default missing documents array, or a generic message if empty
            $defaultMissing = ($fundingOption && is_array($fundingOption->missing_documents)) ? $fundingOption->missing_documents : [];
            return count($defaultMissing) > 0 ? $defaultMissing : ['Please upload your required business documents.'];
        }

        return [];
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
                
                // Business Credit
                if (str_contains($lowerLabel, 'business credit') || $lowerLabel === 'business credit') {
                    $businessScore = $borrower->paydex_score ?? null;
                    if (is_null($businessScore) || $businessScore == 0) {
                        $status = $statusMissing;
                    } else {
                        $score = (float) $businessScore;
                        $maxScore = config('funding.readiness_factors.business_credit.max_score', 850);
                        $strongPct = config('funding.readiness_factors.business_credit.strong_percentage', 80);
                        $goodPct = config('funding.readiness_factors.business_credit.good_percentage', 60);
                        
                        $percentage = ($score / $maxScore) * 100;
                        
                        if ($percentage >= $strongPct) {
                            $status = $statusStrong;
                        } elseif ($percentage >= $goodPct) {
                            $status = $statusGood;
                        } else {
                            $status = $statusMissing;
                        }
                    }
                }
                // Personal Credit
                elseif (str_contains($lowerLabel, 'credit')) {
                    if (is_null($borrower->fico_score) || $borrower->fico_score == 0) {
                        $status = $statusMissing;
                    } elseif (!is_null($fundingOption->min_fico)) {
                        $buffer = config('funding.readiness_factors.personal_credit.strong_buffer', 50);
                        if ($borrower->fico_score < $fundingOption->min_fico) {
                            $status = $statusMissing;
                        } else {
                            $status = ($borrower->fico_score >= $fundingOption->min_fico + $buffer) ? $statusStrong : $statusGood;
                        }
                    } else {
                        $status = $statusGood;
                    }
                }
                // Revenue
                elseif (str_contains($lowerLabel, 'revenue')) {
                    if (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue <= 0) {
                        $status = $statusMissing;
                    } elseif (!is_null($fundingOption->min_monthly_revenue)) {
                        $multiplier = config('funding.readiness_factors.revenue.strong_multiplier', 2.0);
                        if ($borrower->monthly_revenue < $fundingOption->min_monthly_revenue) {
                            $status = $statusMissing;
                        } else {
                            $status = ($borrower->monthly_revenue >= $fundingOption->min_monthly_revenue * $multiplier) ? $statusStrong : $statusGood;
                        }
                    } else {
                        $status = $statusGood;
                    }
                }
                // Time in business
                elseif (str_contains($lowerLabel, 'time') || str_contains($lowerLabel, 'business')) {
                    if (is_null($borrower->years_in_business) || trim($borrower->years_in_business) === '') {
                        $status = $statusMissing;
                    } elseif (!is_null($fundingOption->min_tib_months)) {
                        $bufferMonths = config('funding.readiness_factors.time_in_business.strong_buffer_months', 12);
                        $tibMonths = 0;
                        if (stripos($borrower->years_in_business, 'month') !== false) {
                            preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                            if (isset($matches[1])) $tibMonths = (int)$matches[1];
                        } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                            preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                            if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
                        }
                        
                        if ($tibMonths < $fundingOption->min_tib_months) {
                            $status = $statusMissing;
                        } else {
                            $status = ($tibMonths >= $fundingOption->min_tib_months + $bufferMonths) ? $statusStrong : $statusGood;
                        }
                    } else {
                        $status = $statusGood;
                    }
                }
                // Banking Activity
                elseif (str_contains($lowerLabel, 'bank') || str_contains($lowerLabel, 'activit')) {
                    if (is_null($borrower->avg_bank_balance) || $borrower->avg_bank_balance <= 0) {
                        $status = $statusMissing;
                    } elseif (!is_null($fundingOption->min_average_daily_balance)) {
                        $multiplier = config('funding.readiness_factors.banking_activity.strong_multiplier', 2.0);
                        if ($borrower->avg_bank_balance < $fundingOption->min_average_daily_balance) {
                            $status = $statusMissing;
                        } else {
                            $status = ($borrower->avg_bank_balance >= $fundingOption->min_average_daily_balance * $multiplier) ? $statusStrong : $statusGood;
                        }
                    } else {
                        $status = $statusGood;
                    }
                }
                // Existing Debt
                elseif (str_contains($lowerLabel, 'debt')) {
                    // Check the latest funding application for the most accurate "has_business_debt" status
                    $latestApplication = FundingApplication::where('borrower_id', $borrower->id)->latest()->first();
                    
                    if ($latestApplication) {
                        if ($latestApplication->has_business_debt) {
                            $debtBalance = (float) $latestApplication->debt_balance;
                            $strongMax = config('funding.readiness_factors.existing_debt.strong_max_balance', 1000);
                            $goodMax = config('funding.readiness_factors.existing_debt.good_max_balance', 50000);
                            
                            if ($debtBalance <= $strongMax) {
                                $status = $statusStrong;
                            } elseif ($debtBalance <= $goodMax) {
                                $status = $statusGood;
                            } else {
                                $status = $statusMissing;
                            }
                        } else {
                            // No existing debt is a very strong indicator
                            $status = $statusStrong; 
                        }
                    } else {
                        // Fallback to the borrower's current_business_debt field
                        if (is_null($borrower->current_business_debt) || $borrower->current_business_debt <= 0) {
                            $status = $statusStrong;
                        } else {
                            $debtBalance = (float) $borrower->current_business_debt;
                            $strongMax = config('funding.readiness_factors.existing_debt.strong_max_balance', 1000);
                            $goodMax = config('funding.readiness_factors.existing_debt.good_max_balance', 50000);
                            
                            if ($debtBalance <= $strongMax) {
                                $status = $statusStrong;
                            } elseif ($debtBalance <= $goodMax) {
                                $status = $statusGood;
                            } else {
                                $status = $statusMissing;
                            }
                        }
                    }
                }
                // Documents
                elseif (str_contains($lowerLabel, 'document')) {
                    if (count($missingDocumentsList) > 0) {
                        $status = $statusMissing;
                    } else {
                        $status = $statusGood;
                    }
                }
            }

            $formattedReadinessFactors[] = [
                'label' => $label,
                'status' => $status
            ];
        }

        return $formattedReadinessFactors;
    }
}
