<?php

namespace App\Services\FCH\Common\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Funding\FundingSequencing;
use Carbon\Carbon;

class FundingQualificationService
{
    /** Rules in config/funding_qualification.php judged from the bank statements. */
    public const GROUP_BANKING = 'banking';

    /** The borrower portal page where bank statements are uploaded. */
    public const BANK_STATEMENTS_UPLOAD_PATH = '/documents';

    /** Why a required document does not count (`reason_code` on the factor). */
    public const REASON_NOT_UPLOADED = 'not_uploaded';
    public const REASON_REJECTED = 'rejected';

    // ─── Status helpers ───────────────────────────────────────────────────────

    private function status(string $key): string
    {
        return config("funding_qualification.statuses.{$key}", ucfirst($key));
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    public function evaluateReadinessFactors(Borrower $borrower, int|string $fundingId): array
    {
        $funding = FundingSequencing::findOrFail($fundingId);

        if (!$funding->relationLoaded('lenderVisibilities')) {
            $funding->load('lenderVisibilities');
        }

        return $this->evaluateFactorsForFunding($borrower, $funding);
    }

    private function evaluateFactorsForFunding(Borrower $borrower, FundingSequencing $funding): array
    {
        $explicitFactors = collect($funding->readiness_factors ?? [])
            ->map(fn($factor) => $this->extractLabel($factor))
            ->filter()
            ->toArray();

        $results = [];
        $evaluatedKeys = [];

        // 1. Evaluate explicit factors defined in the funding sequence
        foreach ($explicitFactors as $label) {
            $key = config("funding_qualification.factor_keys.{$label}");
            if (!$key) continue;

            $result = $this->evaluateSingleFactor($key, $borrower, $funding);
            $results[] = array_merge(['label' => $label], $result);
            $evaluatedKeys[] = $key;
        }

        // 2. Dynamically evaluate all other configured factors
        // If a lender requires a factor and the borrower fails it, we must show it as missing.
        $allFactors = config('funding_qualification.factor_keys', []);
        foreach ($allFactors as $label => $key) {
            if (in_array($key, $evaluatedKeys)) {
                continue;
            }

            $result = $this->evaluateSingleFactor($key, $borrower, $funding);

            $status = strtolower($result['status'] ?? '');
            // Only add dynamically if it's explicitly required by a lender OR if it's missing (to show they failed a hard requirement)
            if ($status === 'missing' || $status === 'restricted' || !empty($result['is_required_by_lender'])) {
                $results[] = array_merge(['label' => $label], $result);
            }
        }

        return $results;
    }


    // ─── Core evaluator ───────────────────────────────────────────────────────

    private function evaluateSingleFactor(string $key, Borrower $borrower, FundingSequencing $funding): array
    {
        $rule = config("funding_qualification.rules.{$key}");

        if (!$rule) {
            return ['status' => $this->status('missing')];
        }

        if (!empty($rule['status'])) {
            return $rule;
        }

        $result = match ($rule['type'] ?? 'compare') {
            'documents'             => $this->evaluateDocuments($rule, $borrower, $funding),
            'specific_document'     => $this->evaluateSpecificDocument($rule, $borrower, $funding),
            'ratio'                 => $this->evaluateRatio($rule, $borrower, $funding),
            'date_compare'          => $this->evaluateCompare($rule, $borrower, $funding, asMonths: true),
            'in_array'              => $this->evaluateInArray($rule, $borrower, $funding),
            'not_in_array'          => $this->evaluateNotInArray($rule, $borrower, $funding),
            default                 => $this->evaluateCompare($rule, $borrower, $funding, asMonths: false),
        };

        return $this->withBankingGuidance($result);
    }

    /**
     * A failed banking check (statements missing, or deposits, balance, NSF or
     * negative days outside the lender's limits) tells the borrower in plain
     * words that the bank statements are the problem and where to fix it.
     */
    private function withBankingGuidance(array $result): array
    {
        if (($result['group'] ?? null) !== self::GROUP_BANKING || ($result['status'] ?? null) !== $this->status('failed')) {
            return $result;
        }

        // A missing / rejected / under-review statement gets a message for that
        // case; a figure outside the lender's limits gets the general one.
        $result['message'] = match ($result['reason_code'] ?? null) {
            self::REASON_NOT_UPLOADED => __('funding_sequencing.bank_statements_missing'),
            self::REASON_REJECTED => __('funding_sequencing.bank_statements_rejected'),
            default => __('funding_sequencing.bank_statements_failed'),
        };
        $result['action'] = [
            'label' => __('funding_sequencing.upload_bank_statements'),
            'path' => self::BANK_STATEMENTS_UPLOAD_PATH,
        ];

        return $result;
    }

    // ─── Type handlers ────────────────────────────────────────────────────────

    private function evaluateInArray(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        $bField = $this->columnName($rule['borrower_field'] ?? '');
        $fField = $this->columnName($rule['funding_field']  ?? '');

        if (!$bField || !$fField) {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        if (!$funding->relationLoaded('lenderVisibilities')) {
            $funding->load('lenderVisibilities');
        }

        $lenders = $funding->lenderVisibilities;
        if ($lenders->isEmpty()) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $lendersRequiring = $lenders->filter(function ($lender) use ($fField) {
            $fundingVal = $lender->{$fField};
            if (is_string($fundingVal)) {
                $fundingVal = json_decode($fundingVal, true);
            }
            return !empty($fundingVal) && is_array($fundingVal);
        });

        if ($lendersRequiring->isEmpty()) {
            $rule['status'] = $this->status('good');
            $rule['is_required_by_lender'] = false;
            return $rule;
        }

        $rule['is_required_by_lender'] = true;
        $borrowerVal = $borrower->{$bField};
        $rule['borrower_value'] = $borrowerVal;

        if (is_null($borrowerVal) || $borrowerVal === '') {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        $passCount = 0;

        foreach ($lendersRequiring as $lender) {
            $fundingVal = $lender->{$fField};
            
            if (is_string($fundingVal)) {
                $fundingVal = json_decode($fundingVal, true);
            }

            if (in_array($borrowerVal, $fundingVal, true) || in_array($borrowerVal, $fundingVal)) {
                $passCount++;
            }
        }

        if ($passCount === 0) {
            $rule['status'] = $this->status('failed');
        } else {
            $rule['status'] = $this->status('good');
        }

        return $rule;
    }

    private function evaluateNotInArray(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        $bField = $this->columnName($rule['borrower_field'] ?? '');
        $fField = $this->columnName($rule['funding_field']  ?? '');

        if (!$bField || !$fField) {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        if (!$funding->relationLoaded('lenderVisibilities')) {
            $funding->load('lenderVisibilities');
        }

        $lenders = $funding->lenderVisibilities;
        if ($lenders->isEmpty()) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $lendersRequiring = $lenders->filter(function ($lender) use ($fField) {
            $fundingVal = $lender->{$fField};
            if (is_string($fundingVal)) {
                $fundingVal = json_decode($fundingVal, true);
            }
            return !empty($fundingVal) && is_array($fundingVal);
        });

        if ($lendersRequiring->isEmpty()) {
            $rule['status'] = $this->status('good');
            $rule['is_required_by_lender'] = false;
            return $rule;
        }

        $rule['is_required_by_lender'] = true;
        $borrowerVal = $borrower->{$bField};
        $rule['borrower_value'] = $borrowerVal;

        if (is_null($borrowerVal) || $borrowerVal === '') {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        $passCount = 0;

        foreach ($lendersRequiring as $lender) {
            $fundingVal = $lender->{$fField}; 
            
            if (is_string($fundingVal)) {
                $fundingVal = json_decode($fundingVal, true);
            }

            if (!in_array($borrowerVal, $fundingVal, true) && !in_array($borrowerVal, $fundingVal)) {
                $passCount++;
            }
        }

        if ($passCount === 0) {
            $rule['status'] = $this->status('failed');
        } else {
            $rule['status'] = $this->status('good');
        }

        return $rule;
    }

    private function evaluateCompare(array $rule, Borrower $borrower, FundingSequencing $funding, bool $asMonths): array
    {
        $bField = $this->columnName($rule['borrower_field'] ?? '');
        $fField = $this->columnName($rule['funding_field']  ?? '');

        if (!$bField || !$fField) {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        if (!$funding->relationLoaded('lenderVisibilities')) {
            $funding->load('lenderVisibilities');
        }

        $lenders = $funding->lenderVisibilities;
        if ($lenders->isEmpty()) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $lendersRequiring = $lenders->filter(function ($lender) use ($fField) {
            $fundingVal = $lender->{$fField};
            return !is_null($fundingVal) && $fundingVal !== '';
        });

        if ($lendersRequiring->isEmpty()) {
            $rule['status'] = $this->status('good');
            $rule['is_required_by_lender'] = false;
            return $rule;
        }

        $rule['is_required_by_lender'] = true;
        $borrowerVal = $borrower->{$bField};
        $rule['borrower_value'] = $borrowerVal;

        if (is_null($borrowerVal) || $borrowerVal === '') {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        if ($asMonths) {
            try {
                $borrowerVal = \Carbon\Carbon::parse($borrowerVal)->floatDiffInMonths(\Carbon\Carbon::now());
                $rule['borrower_value'] = $borrowerVal;
            } catch (\Exception $e) {
                $rule['status'] = $this->status('failed');
                return $rule;
            }
        }

        $borrowerVal = (float) $borrowerVal;

        $passCount = 0;
        $strongPassCount = 0;
        $strongOffset     = isset($rule['strong_offset'])     ? (float) $rule['strong_offset']     : null;
        $strongMultiplier = isset($rule['strong_multiplier']) ? (float) $rule['strong_multiplier'] : 1.0;
        $fundingValSample = null;

        foreach ($lendersRequiring as $lender) {
            $fundingVal = (float) $lender->{$fField};
            $isMax = str_starts_with($fField, 'max_');
            
            if ($isMax) {
                if (is_null($fundingValSample) || $fundingVal > $fundingValSample) { // Easiest max limit is the highest
                    $fundingValSample = $fundingVal;
                }
            } else {
                if (is_null($fundingValSample) || $fundingVal < $fundingValSample) { // Easiest min limit is the lowest
                    $fundingValSample = $fundingVal;
                }
            }
            
            $strongThreshold = $strongOffset !== null
                ? $fundingVal + $strongOffset
                : $fundingVal * $strongMultiplier;

            if ($isMax) {
                if ($borrowerVal <= $fundingVal) {
                    $passCount++;
                }
                if ($borrowerVal <= $strongThreshold) {
                    $strongPassCount++;
                }
            } else {
                if ($borrowerVal >= $fundingVal) {
                    $passCount++;
                }
                if ($borrowerVal >= $strongThreshold) {
                    $strongPassCount++;
                }
            }
        }

        $rule['required_value'] = $fundingValSample;

        $totalRequired = $lendersRequiring->count();

        if ($passCount === 0) {
            $rule['status'] = $this->status('failed');
        } elseif ($strongPassCount > 0) {
            $rule['status'] = $this->status('strong');
        } else {
            $rule['status'] = $this->status('good');
        }

        return $rule;
    }

    private function evaluateRatio(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        $bField       = $this->columnName($rule['borrower_field'] ?? '');
        $againstField = $this->columnName($rule['ratio_against']  ?? '');

        if (!$bField || !$againstField) {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        $debt   = (float) ($borrower->{$bField}      ?? 0);
        $annual = (float) ($borrower->{$againstField} ?? 0);

        $rule['borrower_value'] = $debt;
        $rule['against_value'] = $annual;

        if ($debt === 0.0) {
            $rule['status'] = $this->status('strong');
            return $rule;
        }
        if ($annual <= 0.0) {
            $rule['status'] = $this->status('failed');
            return $rule;
        }

        $ratio = $debt / $annual;
        $rule['ratio_value'] = $ratio;

        $fField        = $this->columnName($rule['funding_field'] ?? '');
        $fundingMax    = ($fField && !is_null($funding->{$fField})) ? (float) $funding->{$fField} : null;
        $goodThreshold = $fundingMax ?? (float) ($rule['default_good']   ?? 0.50);
        $strongCeiling =               (float) ($rule['default_strong']  ?? 0.25);

        if ($ratio <= $strongCeiling) {
            $rule['status'] = $this->status('strong');
            return $rule;
        }
        if ($ratio <= $goodThreshold) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $rule['status'] = $this->status('not_good');
        return $rule;
    }

    private function evaluateDocuments(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        if (!$funding->require_documents_uploaded) {
            $rule['status'] = $this->status('good');
            $rule['missing_docs'] = [];
            $rule['required_value'] = 'Uploaded';
            $rule['borrower_value'] = 'Uploaded';
            return $rule;
        }

        $requiredIds = $funding->allowed_documents ?? [];

        if (empty($requiredIds)) {
            $hasAny = BorrowerDocument::where('borrower_id', $borrower->id)
                ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
                ->exists();

            $rule['status'] = $hasAny ? $this->status('good') : $this->status('missing');
            $rule['borrower_value'] = $hasAny ? 'Uploaded' : 'Missing';
            $rule['required_value'] = 'Uploaded';
            $rule['missing_docs'] = $hasAny ? [] : ['Please upload supporting business documents.'];
            return $rule;
        }

        $uploadedIds = BorrowerDocument::where('borrower_id', $borrower->id)
            ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
            ->pluck('document_type_id')
            ->toArray();

        $missingIds = array_values(array_diff($requiredIds, $uploadedIds));

        $rule['required_value'] = 'Uploaded';

        if (empty($missingIds)) {
            $rule['status'] = $this->status('good');
            $rule['borrower_value'] = 'Uploaded';
            $rule['missing_docs'] = [];
            return $rule;
        }

        $missingNames = BorrowerDocType::whereIn('id', $missingIds)->pluck('name')->toArray();

        $rule['status'] = $this->status('failed');
        $rule['borrower_value'] = 'Missing';
        $rule['missing_docs'] = array_values($missingNames);
        return $rule;
    }

    private function evaluateSpecificDocument(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        $docName = $rule['document_name'] ?? '';

        if (!$docName) {
            $rule['status'] = $this->status('failed');
            $rule['required_value'] = 'Uploaded';
            $rule['borrower_value'] = 'Missing';
            return $rule;
        }

        $docType = BorrowerDocType::where('name', $docName)->first();
        if (!$docType) {
            $rule['status'] = $this->status('failed');
            $rule['required_value'] = 'Uploaded';
            $rule['borrower_value'] = 'Missing';
            return $this->withMissingDocumentReason($rule, $borrower, null, $docName);
        }

        // In the borrower's Documents library: Uploaded, Under Review or Accepted
        // (Under Review counts - product decision 2026-10-15).
        $hasDoc = BorrowerDocument::where('borrower_id', $borrower->id)
            ->where('document_type_id', $docType->id)
            ->whereIn('status', [BorrowerDocument::STATUS_UPLOADED, BorrowerDocument::STATUS_UNDER_REVIEW, BorrowerDocument::STATUS_ACCEPTED])
            ->exists();

        $rule['required_value'] = 'Uploaded';

        if ($hasDoc) {
            $rule['status'] = $this->status('good');
            $rule['borrower_value'] = 'Uploaded';
        } else {
            $rule['status'] = $this->status('failed');
            $rule['borrower_value'] = 'Missing';
            $rule['missing_docs'] = [$docName];
            $rule = $this->withMissingDocumentReason($rule, $borrower, $docType, $docName);
        }

        return $rule;
    }

    /**
     * Why a required document does not count, in the borrower's words: none on
     * file, or the latest one was rejected. Plus what is required to pass.
     */
    private function withMissingDocumentReason(array $rule, Borrower $borrower, ?BorrowerDocType $docType, string $docName): array
    {
        $latestStatus = $docType
            ? BorrowerDocument::where('borrower_id', $borrower->id)
                ->where('document_type_id', $docType->id)
                ->latest('id')
                ->value('status')
            : null;

        $rule['reason_code'] = match ($latestStatus) {
            BorrowerDocument::STATUS_REJECTED => self::REASON_REJECTED,
            default => self::REASON_NOT_UPLOADED,
        };
        $rule['reason'] = __("funding_sequencing.bank_statements_reason_{$rule['reason_code']}", ['document' => $docName]);
        $rule['requirement'] = __('funding_sequencing.bank_statements_requirement', ['document' => $docName]);

        return $rule;
    }



    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function columnName(string $field): string
    {
        if (!$field) return '';
        return str_contains($field, '.') ? explode('.', $field)[1] : $field;
    }

    private function extractLabel(mixed $factor): string
    {
        if (is_string($factor)) return trim($factor);
        if (is_array($factor))  return trim($factor['label'] ?? '');
        return '';
    }
}
