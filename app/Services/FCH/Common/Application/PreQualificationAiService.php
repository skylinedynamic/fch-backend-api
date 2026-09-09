<?php

namespace App\Services\FCH\Common\Application;

use App\Models\Borrower\PreQualificationRequest;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationClientAueService;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationInternalAueService;
use App\Services\FCH\Common\PreQualification\Validator\PreQualificationDataIntegrityValidatorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the FCH Pre-Qualification "Run Scenario" flow end to end, per the
 * required execution order:
 *   1. Deterministic backend validation already ran at submission time.
 *   2. FCH Pre-Qualification Form Data Integrity Validator v1.0 (Responses API).
 *   3. If blocking corrections exist, stop here — no AUE is generated.
 *   4. FCH_Internal_Prequalification_AUE_v3 (internal-only, lender-matrix grounded).
 *   5. FCH_Client_AUE_v4 (borrower-safe, built from a sanitized subset of #4).
 *   6. Persist internal and client outputs to separate tables; only the client
 *      output is ever eligible for release outside FCH staff.
 */
class PreQualificationAiService
{
    protected PreQualificationDataIntegrityValidatorService $validator;
    protected PreQualificationInternalAueService $internalAue;
    protected PreQualificationClientAueService $clientAue;

    public function __construct(
        PreQualificationDataIntegrityValidatorService $validator,
        PreQualificationInternalAueService $internalAue,
        PreQualificationClientAueService $clientAue
    ) {
        $this->validator = $validator;
        $this->internalAue = $internalAue;
        $this->clientAue = $clientAue;
    }

    /**
     * Run the full validator -> internal AUE -> client AUE pipeline for one
     * Pre-Qualification application and persist every result.
     */
    public function evaluate(PreQualificationRequest $application, ?int $userId = null): array
    {
        $validation = $this->validator->validate($application);

        if ($validation['blocking']) {
            $application->gpt_status = PreQualificationRequest::GPT_INCORRECT;
            $application->gpt_generated_at = now();
            $application->save();

            return [
                'incorrect' => true,
                'blocked' => true,
                'validation_status' => $validation['status'],
                'borrower_message' => $validation['borrower_message'],
                'qualified_programs' => [],
                'document_checklist' => [],
                'borrower_summary' => $validation['borrower_message'],
                'internal_summary' => null,
                'source' => 'fch_data_integrity_validator',
                'model' => null,
                'latency_ms' => 0,
                'is_fallback' => false,
            ];
        }

        // One id shared by both halves of this evaluation, so the internal and
        // client rows can be paired even across repeat runs.
        $runId = (string) Str::uuid();

        $internal = $this->internalAue->evaluate($application, $userId, $runId);

        // Without an internal evaluation there is nothing to underwrite from, and
        // nothing is invented in its place.
        if (!$internal['ok']) {
            return $this->markFailed($application, $validation, $internal['error']);
        }

        $client = $this->clientAue->generate($application, $internal['data'], $runId);

        // The internal memo is stored and stays available to FCH staff, but the
        // borrower is shown the manual-review state rather than a composed message.
        if (!$client['ok']) {
            return $this->markFailed($application, $validation, $client['error'], $internal);
        }

        $latencyMs = $internal['latency_ms'] + $client['latency_ms'];

        $aiResult = [
            'incorrect' => false,
            'blocked' => false,
            'failed' => false,
            'validation_status' => $validation['status'],
            // Prefer the client-safe wording, but fall back on anything the client call
            // returned empty: "??" only catches null, so an empty array from the client
            // would otherwise blank out a list the internal call had populated.
            'qualified_programs' => self::preferNonEmpty($client['data']['qualified_programs'] ?? [], $internal['data']['qualified_programs'] ?? []),
            'document_checklist' => self::preferNonEmpty($client['data']['document_checklist'] ?? [], $internal['data']['document_checklist'] ?? []),
            'borrower_summary' => $client['data']['borrower_summary'] ?? '',
            'internal_summary' => $internal['data']['internal_summary'] ?? '',
            // Short, lender-free note intended for the Status Notes field.
            'admin_status_note' => $internal['data']['admin_status_note'] ?? '',
            'risk_flags' => $internal['data']['risk_flags'] ?? [],
            'lender_routing_recommendations' => $internal['data']['lender_routing_recommendations'] ?? [],
            'source' => 'openai_responses_api',
            'model' => $internal['model'],
            'latency_ms' => $latencyMs,
            'is_fallback' => false,
        ];

        // Status Notes is a short field (validated at 1000 characters) and is visible
        // to partners, so it gets the concise, lender-free note — never the full
        // internal memo, which lives in the AI Underwriting Result tab.
        $statusNote = (string) ($internal['data']['admin_status_note'] ?? '');
        if (filled($statusNote)) {
            $application->status_note = Str::limit($statusNote, 990, '');
        }
        $application->gpt_status = PreQualificationRequest::GPT_COMPLETED;
        $application->gpt_generated_at = now();
        $application->save();

        return $aiResult;
    }

    /**
     * The evaluation could not be produced. The application is marked FAILED and
     * routed to manual review; no locally composed underwriting outcome is stored
     * or shown, because an eligibility claim must come from an actual evaluation.
     *
     * @param array|null $internal A successful internal evaluation, when the run got
     *                             that far — its memo is already persisted and stays
     *                             available to FCH staff.
     */
    protected function markFailed(
        PreQualificationRequest $application,
        array $validation,
        ?string $reason,
        ?array $internal = null
    ): array {
        // The internal note is model-generated, so it is still worth surfacing to
        // the reviewer who now has to pick this file up by hand.
        $statusNote = (string) ($internal['data']['admin_status_note'] ?? '');
        if (filled($statusNote)) {
            $application->status_note = Str::limit($statusNote, 990, '');
        }

        $application->gpt_status = PreQualificationRequest::GPT_FAILED;
        $application->gpt_generated_at = now();
        $application->save();

        Log::error('FCH Pre-Qualification AUE could not be generated', [
            'pre_qualification_request_id' => $application->id,
            'reason' => $reason,
            'internal_evaluation_completed' => $internal !== null,
        ]);

        return [
            'incorrect' => false,
            'blocked' => false,
            'failed' => true,
            'failure_reason' => $reason,
            'validation_status' => $validation['status'],
            'qualified_programs' => $internal['data']['qualified_programs'] ?? [],
            'document_checklist' => $internal['data']['document_checklist'] ?? [],
            // Nothing borrower-facing was generated, so nothing is offered.
            'borrower_summary' => '',
            'internal_summary' => $internal['data']['internal_summary'] ?? '',
            'admin_status_note' => $statusNote,
            'risk_flags' => $internal['data']['risk_flags'] ?? [],
            'lender_routing_recommendations' => $internal['data']['lender_routing_recommendations'] ?? [],
            'source' => 'openai_responses_api',
            'model' => $internal['model'] ?? null,
            'latency_ms' => (int) ($internal['latency_ms'] ?? 0),
            'is_fallback' => false,
        ];
    }

    /**
     * @param array $preferred The client-safe value, used when it has content.
     * @param array $fallback The internal value, used when the preferred one is empty.
     */
    private static function preferNonEmpty(array $preferred, array $fallback): array
    {
        return !empty($preferred) ? $preferred : $fallback;
    }

    /**
     * Rebuild the same merged ai_result shape returned by evaluate() from the
     * persisted internal/client result rows, so a plain GET of the application
     * (no new evaluation run) shows an identical shape to the run-ai response.
     */
    public static function fromStoredRows(?object $internalRow, ?object $clientRow, ?PreQualificationRequest $application = null): ?array
    {
        // An incorrect / needs-correction evaluation must not surface the previous run's recommendations:
        // the stored rows are from an earlier successful run, and showing them next to
        // an INCORRECT badge invites acting on stale funding programs and memos.
        if ($application && (in_array($application->gpt_status, [PreQualificationRequest::GPT_INCORRECT, 'NEEDS_CORRECTION', 'BLOCKED'], true) || $application->validation_status === PreQualificationRequest::VALIDATION_NEEDS_CORRECTION)) {
            $borrowerMessage = collect((array) $application->validation_issues)
                ->firstWhere('severity', 'BLOCKING')['user_message'] ?? null;

            return [
                'incorrect' => true,
                'blocked' => true,
                'validation_status' => $application->validation_status,
                'borrower_message' => $borrowerMessage,
                'qualified_programs' => [],
                'document_checklist' => [],
                'borrower_summary' => $borrowerMessage,
                'internal_summary' => null,
                'source' => 'fch_data_integrity_validator',
                'model' => null,
                'latency_ms' => 0,
                'is_fallback' => false,
            ];
        }

        // Same reasoning as NEEDS_CORRECTION: a failed evaluation must not present an earlier
        // run's programs and memo as if they were this run's outcome.
        if ($application && $application->gpt_status === PreQualificationRequest::GPT_FAILED) {
            return [
                'incorrect' => false,
                'blocked' => false,
                'failed' => true,
                'failure_reason' => null,
                'validation_status' => $application->validation_status,
                'qualified_programs' => [],
                'document_checklist' => [],
                'borrower_summary' => '',
                'internal_summary' => null,
                'source' => 'openai_responses_api',
                'model' => null,
                'latency_ms' => 0,
                'is_fallback' => false,
            ];
        }

        if (!$internalRow) {
            return null;
        }

        // payload is cast to array on the model; tolerate a raw JSON string too.
        $decode = static function ($payload): array {
            if (is_array($payload)) {
                return $payload;
            }

            return json_decode((string) ($payload ?: '{}'), true) ?: [];
        };

        $internalPayload = $decode($internalRow->payload ?? null);
        $clientPayload = $clientRow ? $decode($clientRow->payload ?? null) : [];

        $isFallback = (bool) ($internalRow->is_placeholder ?? false);
        $latencyMs = (int) ($internalRow->latency_ms ?? 0) + (int) ($clientRow->latency_ms ?? 0);

        $aiResult = [
            'qualified_programs' => self::preferNonEmpty($clientPayload['qualified_programs'] ?? [], $internalPayload['qualified_programs'] ?? []),
            'document_checklist' => self::preferNonEmpty($clientPayload['document_checklist'] ?? [], $internalPayload['document_checklist'] ?? []),
            'borrower_summary' => $clientPayload['borrower_summary'] ?? '',
            'internal_summary' => $internalPayload['internal_summary'] ?? '',
            'admin_status_note' => $internalPayload['admin_status_note'] ?? '',
            'risk_flags' => $internalPayload['risk_flags'] ?? [],
            'lender_routing_recommendations' => $internalPayload['lender_routing_recommendations'] ?? [],
            'source' => $isFallback ? 'fch_underwriting_engine' : 'openai_responses_api',
            'model' => $internalRow->model ?? null,
            'latency_ms' => $latencyMs,
            'is_fallback' => $isFallback,
        ];

        if ($isFallback) {
            $aiResult['fallback_notice'] = 'Generated using the FCH deterministic fallback engine (OpenAI Responses API was unavailable at evaluation time).';
        }

        return $aiResult;
    }
}
