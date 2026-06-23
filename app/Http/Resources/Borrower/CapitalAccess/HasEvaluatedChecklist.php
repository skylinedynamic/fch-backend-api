<?php

namespace App\Http\Resources\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;

trait HasEvaluatedChecklist
{
    /**
     * Evaluate each item of a pre-flight checklist against the borrower's actual profile state.
     *
     * @param  \App\Models\Borrower\Borrower|null $borrower
     * @param  mixed $rawChecklist
     * @return array
     */
    protected function getEvaluatedChecklist($borrower, $rawChecklist): array
    {
        if (is_string($rawChecklist)) {
            $rawChecklist = json_decode($rawChecklist, true);
        }
        $rawChecklist = $rawChecklist ?? [];

        if (!$borrower) {
            return array_map(function ($item, $idx) {
                $label = is_array($item) ? ($item['label'] ?? $item['name'] ?? '') : $item;
                return [
                    'id'       => is_array($item) ? ($item['id'] ?? 'item-' . $idx) : 'item-' . $idx,
                    'label'    => $label,
                    'complete' => false,
                ];
            }, $rawChecklist, array_keys($rawChecklist));
        }

        // Cache checks so we only run each DB query once per resource serialization
        $isBusinessInfoValid   = null;
        $isEinVerified         = null;
        $isPersonalCreditPulled = null;
        $hasBusinessBankAccount = null;
        $isIdentityVerified    = null;

        $evaluated = [];
        foreach ($rawChecklist as $idx => $item) {
            $label    = is_array($item) ? ($item['label'] ?? $item['name'] ?? '') : $item;
            $itemId   = is_array($item) ? ($item['id'] ?? null) : null;
            $complete = false;

            // Match by normalized label
            $normLabel = strtolower(trim($label));

            if ($normLabel === strtolower(CapitalAccess::TASK_BUSINESS_INFORMATION)) {
                if ($isBusinessInfoValid === null) {
                    $businessFields = [
                        'business_name', 'business_ein', 'business_phone', 'business_address',
                        'business_city', 'business_state', 'business_email', 'business_entity_type',
                        'business_industry_type', 'business_start_date'
                    ];
                    $isBusinessInfoValid = true;
                    foreach ($businessFields as $field) {
                        if (empty($borrower->$field)) {
                            $isBusinessInfoValid = false;
                            break;
                        }
                    }
                }
                $complete = $isBusinessInfoValid;
                $itemId   = $itemId ?? 'business_info';
            } elseif ($normLabel === strtolower(CapitalAccess::TASK_EIN_VERIFIED)) {
                if ($isEinVerified === null) {
                    $isEinVerified = false;
                    if ($borrower->current_tier > 1) {
                        $isEinVerified = true;
                    } elseif ($borrower->current_tier == 1) {
                        $latestRequest = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                            ->where('task_id', BorrowerTaskVerificationRequest::TASK_EIN_VERIFICATION)
                            ->latest()
                            ->first();
                        if ($latestRequest && $latestRequest->status === BorrowerTaskVerificationRequest::COMPLETED) {
                            $isEinVerified = true;
                        }
                    }
                }
                $complete = $isEinVerified;
                $itemId   = $itemId ?? 'ein_verified';
            } elseif ($normLabel === strtolower(CapitalAccess::TASK_PERSONAL_CREDIT_PULLED)) {
                if ($isPersonalCreditPulled === null) {
                    $isPersonalCreditPulled = (bool)($borrower->credit_profile_updated ?? false);
                }
                $complete = $isPersonalCreditPulled;
                $itemId   = $itemId ?? 'personal_credit_pulled';
            } elseif ($normLabel === strtolower(CapitalAccess::TASK_BUSINESS_BANK_ACCOUNT)) {
                if ($hasBusinessBankAccount === null) {
                    $hasBusinessBankAccount = $borrower->bankAccounts()->exists();
                }
                $complete = $hasBusinessBankAccount;
                $itemId   = $itemId ?? 'business_bank_account';
            } elseif ($normLabel === strtolower(CapitalAccess::TASK_IDENTITY_VERIFIED)) {
                if ($isIdentityVerified === null) {
                    $isIdentityVerified = false;
                    if ($borrower->current_tier > 1) {
                        $isIdentityVerified = true;
                    } elseif ($borrower->current_tier == 1) {
                        $latestRequest = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                            ->where('task_id', BorrowerTaskVerificationRequest::TASK_IDENTITY_VERIFICATION)
                            ->latest()
                            ->first();
                        if ($latestRequest && $latestRequest->status === BorrowerTaskVerificationRequest::COMPLETED) {
                            $isIdentityVerified = true;
                        }
                    }
                }
                $complete = $isIdentityVerified;
                $itemId   = $itemId ?? 'identity_verified';
            } elseif ($normLabel === strtolower(CapitalAccess::TASK_NO_RECENT_DEROGATORIES)) {
                $complete = true;
                $itemId   = $itemId ?? 'no_recent_derogatories';
            } else {
                $complete = is_array($item) ? ($item['complete'] ?? true) : true;
                $itemId   = $itemId ?? 'item-' . $idx;
            }

            $evaluated[] = [
                'id'       => $itemId,
                'label'    => $label,
                'complete' => $complete,
            ];
        }

        return $evaluated;
    }
}
