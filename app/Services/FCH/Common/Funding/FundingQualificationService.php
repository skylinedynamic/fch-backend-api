<?php

namespace App\Services\FCH\Common\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Funding\FundingSequencing;
use Carbon\Carbon;

class FundingQualificationService
{
    // ─── Status helpers ───────────────────────────────────────────────────────

    private function status(string $key): string
    {
        return config("funding_qualification.statuses.{$key}", ucfirst($key));
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    public function evaluateReadinessFactors(Borrower $borrower, int|string $fundingId): array
    {
        $funding = FundingSequencing::findOrFail($fundingId);

        return collect($funding->readiness_factors ?? [])
            ->map(fn($factor) => $this->extractLabel($factor))
            ->filter()
            ->map(function (string $label) use ($borrower, $funding) {
                $key = config("funding_qualification.factor_keys.{$label}");
                if (!$key) return null;

                $result = $this->evaluateSingleFactor($key, $borrower, $funding);

                return array_merge(['label' => $label], $result);
            })
            ->filter()
            ->values()
            ->toArray();
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

        return match ($rule['type'] ?? 'compare') {
            'documents'             => $this->evaluateDocuments($rule, $borrower, $funding),
            'specific_document'     => $this->evaluateSpecificDocument($rule, $borrower, $funding),
            'ratio'                 => $this->evaluateRatio($rule, $borrower, $funding),
            'date_compare'          => $this->evaluateCompare($rule, $borrower, $funding, asMonths: true),
            'in_array'              => $this->evaluateInArray($rule, $borrower, $funding),
            default                 => $this->evaluateCompare($rule, $borrower, $funding, asMonths: false),
        };
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

        $borrowerVal = $borrower->{$bField};
        $fundingVal  = $funding->{$fField}; // Expected to be an array or JSON cast to array

        $rule['borrower_value'] = $borrowerVal;
        $rule['funding_value']  = $fundingVal;

        if (is_null($borrowerVal) || $borrowerVal === '') {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        if (empty($fundingVal) || !is_array($fundingVal)) {
            // If funding doesn't restrict it, it's good
            $rule['status'] = $this->status('good');
            return $rule;
        }

        if (in_array($borrowerVal, $fundingVal, true)) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $rule['status'] = $this->status('not_good');
        return $rule;
    }

    private function evaluateCompare(array $rule, Borrower $borrower, FundingSequencing $funding, bool $asMonths): array
    {
        $bField = $this->columnName($rule['borrower_field'] ?? '');
        $fField = $this->columnName($rule['funding_field']  ?? '');

        if (!$bField || !$fField) {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        $borrowerVal = $borrower->{$bField};
        $rule['borrower_value'] = $borrowerVal;

        if (is_null($borrowerVal) || $borrowerVal === '') {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        if ($asMonths) {
            try {
                $borrowerVal = Carbon::parse($borrowerVal)->diffInMonths(Carbon::now());
                $rule['borrower_value'] = $borrowerVal;
            } catch (\Exception $e) {
                $rule['status'] = $this->status('missing');
                return $rule;
            }
        }

        $borrowerVal = (float) $borrowerVal;

        if (!$funding->relationLoaded('lenderVisibilities')) {
            $funding->load('lenderVisibilities');
        }

        $lenders = $funding->lenderVisibilities;
        if ($lenders->isEmpty()) {
            $rule['status'] = $this->status('good');
            return $rule;
        }

        $passCount = 0;
        $strongPassCount = 0;
        $totalLenders = $lenders->count();

        $strongOffset     = isset($rule['strong_offset'])     ? (float) $rule['strong_offset']     : null;
        $strongMultiplier = isset($rule['strong_multiplier']) ? (float) $rule['strong_multiplier'] : 1.0;

        foreach ($lenders as $lender) {
            $fundingVal = $lender->{$fField};
            
            if (is_null($fundingVal) || $fundingVal === '') {
                $passCount++;
                $strongPassCount++;
                continue;
            }

            $fundingVal = (float) $fundingVal;
            $strongThreshold = $strongOffset !== null
                ? $fundingVal + $strongOffset
                : $fundingVal * $strongMultiplier;

            $isMax = str_starts_with($fField, 'max_');

            if ($isMax) {
                if ($borrowerVal <= $fundingVal) {
                    $passCount++;
                }
                // For max_ fields, 'strong' means lower. But we don't have strong offset for max_ fields yet.
                // Assuming strong logic applies to min_ fields primarily, or offset is negative for max.
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

        if ($passCount < $totalLenders) {
            $rule['status'] = $this->status('missing');
        } elseif ($strongPassCount === $totalLenders && $totalLenders > 0) {
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
            $rule['status'] = $this->status('missing');
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
            $rule['status'] = $this->status('missing');
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
            return $rule;
        }

        $requiredIds = $funding->allowed_documents ?? [];

        if (empty($requiredIds)) {
            $hasAny = BorrowerDocument::where('borrower_id', $borrower->id)
                ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
                ->exists();

            $rule['status'] = $hasAny ? $this->status('good') : $this->status('missing');
            $rule['missing_docs'] = $hasAny ? [] : ['Please upload supporting business documents.'];
            return $rule;
        }

        $uploadedIds = BorrowerDocument::where('borrower_id', $borrower->id)
            ->where('status', '!=', BorrowerDocument::STATUS_REJECTED)
            ->pluck('document_type_id')
            ->toArray();

        $missingIds = array_values(array_diff($requiredIds, $uploadedIds));

        if (empty($missingIds)) {
            $rule['status'] = $this->status('good');
            $rule['missing_docs'] = [];
            return $rule;
        }

        $missingNames = BorrowerDocType::whereIn('id', $missingIds)->pluck('name')->toArray();

        $rule['status'] = $this->status('missing');
        $rule['missing_docs'] = array_values($missingNames);
        return $rule;
    }

    private function evaluateSpecificDocument(array $rule, Borrower $borrower, FundingSequencing $funding): array
    {
        $docName = $rule['document_name'] ?? '';

        if (!$docName) {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        $docType = BorrowerDocType::where('name', $docName)->first();
        if (!$docType) {
            $rule['status'] = $this->status('missing');
            return $rule;
        }

        $hasDoc = BorrowerDocument::where('borrower_id', $borrower->id)
            ->where('document_type_id', $docType->id)
            ->whereIn('status', [BorrowerDocument::STATUS_UPLOADED, BorrowerDocument::STATUS_ACCEPTED])
            ->exists();

        if ($hasDoc) {
            $rule['status'] = $this->status('good');
        } else {
            $rule['status'] = $this->status('missing');
            $rule['missing_docs'] = [$docName];
        }

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
