<?php

namespace App\Services\FCH\Common\Application;

use App\Models\Borrower\PreQualificationRequest;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationClientAueService;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationInternalAueService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the FCH Pre-Qualification "Run Scenario" flow end to end.
 *
 * One agent does the underwriting, configured in Settings > OpenAI
 * Configuration. Its instructions, its output schema, its model and its
 * reasoning effort live on the OpenAI platform and are read on every run, so
 * nothing about how a borrower is assessed is written in this codebase.
 *
 * The order is:
 *   1. Deterministic backend validation already ran at submission time.
 *   2. The agent evaluates, grounded in the live lender matrix. Its result is
 *      stored as the internal row and stays with FCH staff.
 *   3. Its own data_status decides whether the borrower's answers are usable.
 *      CONFLICTING or INSUFFICIENT means they have something to correct, and no
 *      borrower-facing assessment is released on figures it just called
 *      unreliable.
 *   4. Otherwise the borrower-safe part of that same evaluation is stored as
 *      the borrower row — the only one ever released outside FCH staff.
 */
class PreQualificationAiService
{
    protected PreQualificationInternalAueService $internalAue;
    protected PreQualificationClientAueService $clientAue;

    public function __construct(
        PreQualificationInternalAueService $internalAue,
        PreQualificationClientAueService $clientAue
    ) {
        $this->internalAue = $internalAue;
        $this->clientAue = $clientAue;
    }

    /**
     * Run the one Pre-Qualification agent for an application and persist both
     * halves of its result.
     *
     * One agent underwrites. Its evaluation is stored as the internal row, the
     * borrower-safe part of it as the borrower row, and whether the submitted
     * information is usable at all is that same agent's data_status — there is
     * no separate validator with a second opinion about the same facts.
     */
    public function evaluate(PreQualificationRequest $application, ?int $userId = null): array
    {
        // One id shared by both halves of this evaluation, so the internal and
        // client rows can be paired even across repeat runs.
        $runId = (string) Str::uuid();

        $internal = $this->internalAue->evaluate($application, $userId, $runId);

        // Without an evaluation there is nothing to underwrite from, and nothing
        // is invented in its place.
        if (!$internal['ok']) {
            return $this->markFailed($application, ['status' => null], $internal['error']);
        }

        // The agent's own verdict on the information it was given, recorded
        // before anything is released: a borrower whose figures conflict is
        // asked to correct them rather than handed a readiness assessment
        // built on them.
        $validation = $this->recordDataIntegrity($application, $internal['data']);

        if ($validation['blocking']) {
            // The evaluation is stored and stays with FCH staff; the borrower is
            // shown what the agent said needs correcting.
            $application->gpt_status = PreQualificationRequest::GPT_COMPLETED;
            $application->gpt_generated_at = now();
            $application->save();

            return [
                'incorrect' => true,
                'blocked' => true,
                'validation_status' => $validation['status'],
                'borrower_message' => $validation['borrower_message'],
                'internal' => $internal['data'],
                // Nothing borrower-facing is released on information the agent
                // itself called conflicting or insufficient.
                'client' => null,
                'admin_status_note' => self::statusNoteFor($internal['data']),
                'source' => 'openai_agent',
                'model' => $internal['model'],
                'latency_ms' => $internal['latency_ms'],
                'is_fallback' => false,
            ];
        }

        $client = $this->clientAue->generate($application, $internal['data'], $runId);

        // The evaluation is stored and stays available to FCH staff, but the
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
            'source' => 'openai_agent',
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
     * Record what the agent said about the information it was given.
     *
     * The agent reports a data_status over the borrower's own answers. CONFLICTING
     * means two of them cannot both be true; INSUFFICIENT means one needed for a
     * meaningful assessment is missing. Either way the borrower has something to
     * correct, and an assessment built on those figures would be an assessment of
     * figures nobody can rely on — so it is withheld and they are asked to fix it.
     *
     * COMPLETE and PARTIAL are not blocking. PARTIAL is the ordinary state of a
     * pre-qualification: no documents are collected at this stage, and the agent's
     * own instructions forbid treating their absence as a deficiency.
     *
     * The findings shown to the borrower are the agent's own wording for the
     * conditions it graded BLOCKING, scrubbed of lender names on the same rule the
     * borrower assessment is. The severity grade and the evidence fields the agent
     * reasoned over stay internal, as they do everywhere else.
     *
     * @return array{blocking: bool, status: ?string, borrower_message: ?string}
     */
    protected function recordDataIntegrity(PreQualificationRequest $application, array $internal): array
    {
        $blockingStatuses = (array) config('prequalification_aue.blocking_data_statuses', ['CONFLICTING', 'INSUFFICIENT']);
        $dataStatus = (string) ($internal['data_status'] ?? '');
        $blocking = in_array($dataStatus, $blockingStatuses, true);

        $issues = [];

        if ($blocking) {
            $issues = collect((array) ($internal['conditions_and_flags'] ?? []))
                ->filter(fn ($flag) => is_array($flag) && ($flag['severity'] ?? null) === 'BLOCKING')
                ->map(fn ($flag) => [
                    'severity' => 'BLOCKING',
                    'field' => (string) ($flag['category'] ?? ''),
                    // What the borrower is being asked to correct, and what to do
                    // about it — the agent's words, with lender names removed.
                    'user_message' => PreQualificationClientAueService::scrubLenderNamesFrom(trim(
                        (string) ($flag['finding'] ?? '') . ' ' . (string) ($flag['required_action'] ?? '')
                    )),
                    'internal_message' => (string) ($flag['finding'] ?? ''),
                    'suggested_action' => (string) ($flag['required_action'] ?? ''),
                ])
                ->filter(fn ($issue) => $issue['user_message'] !== '')
                ->values()
                ->all();
        }

        $application->validation_status = $blocking
            ? PreQualificationRequest::VALIDATION_INCORRECT
            : PreQualificationRequest::VALIDATION_PASSED;
        $application->validation_version = (string) ($internal['prompt_version'] ?? '');
        // Deliberately left empty. validation_summary is one of the few fields
        // the borrower-facing resource exposes verbatim, and every summary the
        // agent writes — the executive conclusion, the final disposition — is
        // internal and may name a lender or a routing decision. The borrower
        // reads the per-finding messages instead, which are built for them.
        $application->validation_summary = '';
        $application->validation_issues = $issues;
        $application->validated_at = now();
        $application->save();

        return [
            'blocking' => $blocking,
            'status' => $application->validation_status,
            'borrower_message' => $issues[0]['user_message'] ?? null,
        ];
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
            'source' => 'openai_agent',
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

            // The evaluation that called the data unusable is this run's own, so
            // it is not stale and staff need it — it is the only account of why
            // the file was flagged. It is withheld only when the run did not get
            // far enough to produce one. The borrower row stays withheld either
            // way: no assessment is released on figures the agent itself called
            // conflicting or insufficient.
            $internalPayload = null;

            if ($internalRow && $application->gpt_status === PreQualificationRequest::GPT_COMPLETED) {
                $internalPayload = is_array($internalRow->payload ?? null)
                    ? $internalRow->payload
                    : (json_decode((string) ($internalRow->payload ?: '{}'), true) ?: []);
            }

            return [
                'incorrect' => true,
                'blocked' => true,
                'validation_status' => $application->validation_status,
                'borrower_message' => $borrowerMessage,
                'internal' => $internalPayload,
                'admin_status_note' => $internalPayload ? self::statusNoteFor($internalPayload) : null,
                'client' => null,
                'source' => 'openai_agent',
                'model' => $internalPayload ? ($internalRow->model ?? null) : null,
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
                'source' => 'openai_agent',
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
            'source' => $isFallback ? 'fch_underwriting_engine' : 'openai_agent',
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
