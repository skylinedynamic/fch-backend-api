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
                
                // Business Credit
                if (str_contains($lowerLabel, 'business credit') || $lowerLabel === 'business credit') {
                    $businessScore = $borrower->paydex_score ?? null;
                    if (is_null($businessScore) || $businessScore == 0) {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            $buffer = 20; // Default offset for business credit

                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_fico)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($businessScore >= $lender->min_fico) {
                                        $passCount++;
                                    }
                                    if ($businessScore >= $lender->min_fico + $buffer) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Personal Credit
                elseif (str_contains($lowerLabel, 'credit')) {
                    if (is_null($borrower->fico_score) || $borrower->fico_score == 0) {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            $buffer = config('funding.readiness_factors.personal_credit.strong_buffer', 50);

                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_fico)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($borrower->fico_score >= $lender->min_fico) {
                                        $passCount++;
                                    }
                                    if ($borrower->fico_score >= $lender->min_fico + $buffer) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Revenue
                elseif (str_contains($lowerLabel, 'revenue') || str_contains($lowerLabel, 'sales')) {
                    if (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue <= 0) {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            $multiplier = config('funding.readiness_factors.revenue.strong_multiplier', 2.0);
                            
                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_monthly_revenue)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($borrower->monthly_revenue >= $lender->min_monthly_revenue) {
                                        $passCount++;
                                    }
                                    if ($borrower->monthly_revenue >= $lender->min_monthly_revenue * $multiplier) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Time in business
                elseif (str_contains($lowerLabel, 'time') || str_contains($lowerLabel, 'business')) {
                    if (is_null($borrower->years_in_business) || trim($borrower->years_in_business) === '') {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            $bufferMonths = config('funding.readiness_factors.time_in_business.strong_buffer_months', 12);

                            $tibMonths = 0;
                            if (stripos($borrower->years_in_business, 'month') !== false) {
                                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                                if (isset($matches[1])) $tibMonths = (int)$matches[1];
                            } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                                if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
                            }

                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_tib_months)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($tibMonths >= $lender->min_tib_months) {
                                        $passCount++;
                                    }
                                    if ($tibMonths >= $lender->min_tib_months + $bufferMonths) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Banking Activity
                elseif (str_contains($lowerLabel, 'bank') || str_contains($lowerLabel, 'activit')) {
                    $docName = config('funding_qualification.rules.banking_activity.document_name', BorrowerDocType::BANK_STATEMENTS);
                    $docType = BorrowerDocType::where('name', $docName)->first();
                    if ($docType) {
                        $hasDoc = BorrowerDocument::where('borrower_id', $borrower->id)
                            ->where('document_type_id', $docType->id)
                            ->whereIn('status', [BorrowerDocument::STATUS_UPLOADED, BorrowerDocument::STATUS_ACCEPTED])
                            ->exists();
                            
                        if ($hasDoc) {
                            $status = $statusGood;
                        } else {
                            $status = $statusMissing;
                        }
                    } else {
                        $status = $statusMissing;
                    }
                }
                // Minimum Monthly Deposits
                elseif (str_contains($lowerLabel, 'deposit')) {
                    // For now, mapping monthly deposits to borrower's monthly_revenue as they are correlated
                    if (is_null($borrower->monthly_revenue) || $borrower->monthly_revenue <= 0) {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            
                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_monthly_deposits)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($borrower->monthly_revenue >= $lender->min_monthly_deposits) {
                                        $passCount++;
                                    }
                                    if ($borrower->monthly_revenue >= $lender->min_monthly_deposits * 1.5) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Minimum Average Balance
                elseif (str_contains($lowerLabel, 'balance')) {
                    if (is_null($borrower->avg_bank_balance)) {
                        $status = $statusMissing;
                    } else {
                        $lenders = $fundingOption->lenderVisibilities ?? collect();
                        if ($lenders->isEmpty()) {
                            $status = $statusGood;
                        } else {
                            $passCount = 0;
                            $strongPassCount = 0;
                            $totalLenders = $lenders->count();
                            
                            foreach ($lenders as $lender) {
                                if (is_null($lender->min_average_daily_balance)) {
                                    $passCount++;
                                    $strongPassCount++;
                                } else {
                                    if ($borrower->avg_bank_balance >= $lender->min_average_daily_balance) {
                                        $passCount++;
                                    }
                                    if ($borrower->avg_bank_balance >= $lender->min_average_daily_balance * 1.5) {
                                        $strongPassCount++;
                                    }
                                }
                            }

                            if ($passCount < $totalLenders) {
                                $status = $statusMissing;
                            } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
                                $status = $statusStrong;
                            } else {
                                $status = $statusGood;
                            }
                        }
                    }
                }
                // Documents
                elseif (str_contains($lowerLabel, 'document')) {
                    // if (count($missingDocumentsList) > 0) {
                    //     $status = $statusMissing;
                    // } else {
                    //     $status = $statusGood;
                    // }
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
}
