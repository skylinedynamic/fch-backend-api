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
                // The validator blocked before either evaluation ran.
                'internal' => null,
                'client' => null,
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

        // The two payloads are kept whole and separate rather than flattened into
        // one another. Their field sets are defined by the v3 and v4 schemas and
        // overlap only by coincidence, so merging them would blur which half a
        // value came from — and it is exactly that boundary the resource layer
        // relies on when it withholds the internal half from a partner.
        $aiResult = [
            'incorrect' => false,
            'blocked' => false,
            'failed' => false,
            'validation_status' => $validation['status'],
            'internal' => $internal['data'],
            'client' => $client['data'],
            // Offered to staff as a one-click Status Notes insert. Composed from
            // the enum fields only, so it is safe for the partners who see it.
            'admin_status_note' => self::statusNoteFor($internal['data']),
            'source' => 'openai_responses_api',
            'model' => $internal['model'],
            'latency_ms' => $latencyMs,
            'is_fallback' => false,
        ];

        // Status Notes is a short field and is visible to partners, so it gets the
        // concise, lender-free note — never the full internal memo, which lives in
        // the AI Underwriting Result tab. Truncated to the same limit the update
        // endpoint enforces, so a note written here is one a reviewer can still
        // save back from the form.
        $statusNote = self::statusNoteFor($internal['data']);
        if (filled($statusNote)) {
            $application->status_note = Str::limit($statusNote, PreQualificationRequest::STATUS_NOTE_MAX_LENGTH, '');
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
        $statusNote = $internal ? self::statusNoteFor($internal['data']) : '';
        if (filled($statusNote)) {
            $application->status_note = Str::limit($statusNote, PreQualificationRequest::STATUS_NOTE_MAX_LENGTH, '');
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
            // The internal half survives when the run got that far, so staff still
            // have the evaluation. Nothing borrower-facing was generated, so the
            // client half is null rather than a locally composed stand-in.
            'internal' => $internal['data'] ?? null,
            'client' => null,
            'source' => 'openai_responses_api',
            'model' => $internal['model'] ?? null,
            'latency_ms' => (int) ($internal['latency_ms'] ?? 0),
            'is_fallback' => false,
        ];
    }

    /**
     * The short note written to Status Notes.
     *
     * Status Notes is visible to partners, and the v3 schema has no field written
     * for that audience — every free-text field in it is internal and may name a
     * lender or quote a threshold. So the note is composed here from the two
     * enum fields alone, which can only ever hold one of their declared values.
     */
    private static function statusNoteFor(array $internal): string
    {
        $stance = (string) ($internal['overall_stance'] ?? '');
        $nextAction = (string) ($internal['routing_recommendation']['next_action'] ?? '');

        $parts = array_filter([
            $stance !== '' ? 'AUE stance: ' . str_replace('_', ' ', $stance) : null,
            $nextAction !== '' ? 'Next action: ' . str_replace('_', ' ', $nextAction) : null,
        ]);

        return implode('. ', $parts);
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
                // Stale rows from an earlier run are deliberately not surfaced.
                'internal' => null,
                'client' => null,
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
                'internal' => null,
                'client' => null,
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

        // Same shape evaluate() returns, so a plain GET and a fresh run are
        // indistinguishable to the caller.
        $aiResult = [
            'internal' => $internalPayload,
            'client' => $clientPayload ?: null,
            'admin_status_note' => self::statusNoteFor($internalPayload),
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
