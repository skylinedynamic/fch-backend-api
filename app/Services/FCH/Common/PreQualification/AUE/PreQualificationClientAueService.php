<?php

namespace App\Services\FCH\Common\PreQualification\AUE;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Lender\Lender;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Support\Facades\Log;

/**
 * FCH_Client_AUE_v4 — borrower-safe generation.
 *
 * Receives ONLY the sanitized subset of the internal AUE output (generic qualified
 * programs + document checklist) — never the internal memo, risk flags, lender
 * routing, or lender matrix. Output is additionally scanned for lender names before
 * being stored/released, as defense in depth against a leaking prompt or model output.
 */
class PreQualificationClientAueService
{
    public const PROMPT_VERSION = 'FCH_Client_AUE_v4';

    protected OpenAiResponsesService $client;

    /** Correlates this run's client row with its internal counterpart. */
    protected ?string $runId = null;

    /** OpenAI's identifiers for the call behind the row about to be written. */
    protected ?string $requestId = null;

    public function __construct(OpenAiResponsesService $client)
    {
        $this->client = $client;
    }

    /**
     * @param array $internalResult Output of PreQualificationInternalAueService::evaluate()['data']
     */
    public function generate(PreQualificationRequest $application, array $internalResult, ?string $runId = null): array
    {
        $this->runId = $runId;
        $sanitizedInput = $this->buildSanitizedInput($application, $internalResult);

        if (!$this->client->hasApiKey()) {
            return $this->failed('No OpenAI API key configured.');
        }

        $instructions = file_get_contents(__DIR__ . '/FCH_Client_AUE_v4_Responses_Instructions.txt');
        $schema = json_decode(file_get_contents(__DIR__ . '/FCH_Client_AUE_v4_Response_Schema.json'), true);

        $result = $this->client->run(
            model: $this->client->defaultModel(),
            instructions: $instructions,
            input: $sanitizedInput,
            schemaName: 'fch_client_aue_v4',
            schema: $schema,
            reasoningEffort: 'low',
            logContext: [
                'prompt_version' => self::PROMPT_VERSION,
                'pre_qualification_request_id' => $application->id,
                'step' => 'client_aue',
            ],
        );

        if (!$result['ok']) {
            return $this->failed($result['error']);
        }

        // Carried to persist() so the stored row points at the exact OpenAI call.
        $this->requestId = $result['request_id'] ?? null;

        $data = $result['data'];

        if ($this->containsProtectedInformation($data)) {
            Log::warning('FCH Client AUE output blocked for containing protected internal information', [
                'pre_qualification_request_id' => $application->id,
            ]);

            return $this->failed('Generated client output failed the internal-information safety check.');
        }

        $this->persist($application, $data, self::PROMPT_VERSION, $result['model'], $result['latency_ms']);

        return [
            'ok' => true,
            'data' => $data,
            'is_fallback' => false,
            'model' => $result['model'],
            'latency_ms' => $result['latency_ms'],
            'error' => null,
        ];
    }

    /**
     * Build the borrower-safe subset of the internal v3 result.
     *
     * Whitelist, not blacklist: only the listed fields are carried across, so a
     * new internal field cannot reach the borrower by being forgotten here. The
     * internal conclusion, underwriting analysis, AUS determination, lender
     * matches, routing recommendation, condition severities and evidence fields
     * are all deliberately absent, and whatever survives is scrubbed of lender
     * names before it is sent.
     */
    protected function buildSanitizedInput(PreQualificationRequest $application, array $internal): array
    {
        $input = [
            'submission_id' => (string) $application->id,
            'borrower_profile' => [
                'business_name' => $application->business_name,
                'funding_purpose' => $application->purpose_of_funding,
                'amount_requested' => (float) ($application->funding_requested_amount ?? 0),
            ],

            // How complete the borrower's own submission was — drives how much the
            // assessment can commit to, and is about their data, not FCH policy.
            'data_status' => $internal['data_status'] ?? 'INSUFFICIENT',

            // The borrower's own submitted values and how each was sourced. Exact
            // FICO and score-source rows are dropped: prequalification personal
            // credit is the borrower-selected band only.
            'borrower_request_snapshot' => array_values(array_map(fn ($row) => [
                'field' => (string) ($row['field'] ?? ''),
                'value' => $row['value'] ?? null,
                'source_status' => (string) ($row['source_status'] ?? 'NOT_PROVIDED'),
            ], array_filter(
                (array) ($internal['borrower_request_snapshot'] ?? []),
                fn ($row) => !preg_match('/fico|score[ _]source|band[ _]source/i', (string) ($row['field'] ?? ''))
            ))),

            'strengths' => array_values(array_filter((array) ($internal['strengths'] ?? []), 'is_string')),

            // Only what the borrower must act on. The severity code and the
            // evidence fields underwriting reasoned over stay internal.
            'attention_items' => array_values(array_map(fn ($flag) => [
                'area' => (string) ($flag['category'] ?? ''),
                'finding' => (string) ($flag['finding'] ?? ''),
                'required_action' => (string) ($flag['required_action'] ?? ''),
            ], (array) ($internal['conditions_and_flags'] ?? []))),

            // Product categories only — never the lender behind them, and never
            // the fit status, rationale or priority ordering, which are routing
            // decisions rather than borrower information.
            'funding_paths' => array_values(array_map(fn ($path) => [
                'product_category' => (string) ($path['product_category'] ?? ''),
                'amount_min' => $path['amount_min'] ?? null,
                'amount_max' => $path['amount_max'] ?? null,
                'amount_basis' => (string) ($path['amount_basis'] ?? ''),
                'conditions' => array_values(array_filter((array) ($path['conditions'] ?? []), 'is_string')),
                'required_documents' => array_values(array_filter((array) ($path['required_documents'] ?? []), 'is_string')),
            ], (array) ($internal['recommended_funding_paths'] ?? []))),

            // Document lists only. "path_or_lender" is dropped outright rather
            // than inspected, because it may name a lender.
            'required_documents' => array_values(array_unique(collect((array) ($internal['required_documentation'] ?? []))
                ->flatMap(fn ($group) => array_filter((array) ($group['items'] ?? []), 'is_string'))
                ->all())),

            'credit_building_pathway' => array_values(array_filter((array) ($internal['credit_building_pathway'] ?? []), 'is_string')),

            // Whether a human still has to look at this, without the reasons why.
            'manual_review_required' => (bool) ($internal['manual_review']['required'] ?? false),
        ];

        return $this->scrubLenderNames($input);
    }

    /**
     * Remove any lender name that survived the whitelist inside free text.
     *
     * A product category or condition string can still quote a lender, so every
     * string in the payload is swept before it leaves for the client model.
     */
    protected function scrubLenderNames(array $input): array
    {
        $names = $this->lenderNames();

        if ($names === []) {
            return $input;
        }

        array_walk_recursive($input, function (&$value) use ($names) {
            if (!is_string($value) || $value === '') {
                return;
            }

            $value = trim(preg_replace('/\s{2,}/', ' ', str_ireplace($names, '[redacted]', $value)));
        });

        return $input;
    }

    /** Every lender name on record — inactive names are protected too. */
    protected function lenderNames(): array
    {
        return Lender::query()
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn ($name) => trim($name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * No result. Nothing is persisted and nothing is shown to the borrower: a
     * locally composed "You're Prequalified" message would assert an underwriting
     * outcome that no underwriting produced.
     */
    protected function failed(?string $reason): array
    {
        return [
            'ok' => false,
            'data' => null,
            'is_fallback' => false,
            'model' => null,
            'latency_ms' => 0,
            'error' => $reason,
        ];
    }

    protected function persist(PreQualificationRequest $application, array $data, string $promptVersion, ?string $model, int $latencyMs, bool $isPlaceholder = false): void
    {
        PreQualificationGptResult::create([
            'pre_qualification_request_id' => $application->id,
            'type' => PreQualificationGptResult::TYPE_CLIENT,
            'run_id' => $this->runId,
            'prompt_version' => $promptVersion,
            'model' => $model,
            'openai_request_id' => $this->requestId,
            'payload' => $data,
            'is_placeholder' => $isPlaceholder,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * Defense-in-depth scan: block the client output if it names any lender
     * (active or inactive — an inactive lender's name is still protected internal
     * information) or references obvious internal-only terminology.
     */
    protected function containsProtectedInformation(array $data): bool
    {
        // Every string in the response, whatever its depth — a new v4 field is
        // then covered automatically instead of silently escaping the scan.
        $strings = [];
        array_walk_recursive($data, function ($value) use (&$strings) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        });

        $haystack = strtolower(implode(' ', $strings));

        foreach ($this->lenderNames() as $name) {
            if (str_contains($haystack, strtolower($name))) {
                return true;
            }
        }

        // Internal-only vocabulary, plus the prohibited borrower-facing claims:
        // this assessment reports readiness, it never announces an approval.
        $forbiddenTerms = [
            'aus model', 'mca aus', 'regular aus', 'iso routing', 'risk flag',
            'internal_summary', 'underwriting memo', 'internal use only',
            'do not distribute', 'lender matrix', 'routing recommendation',
            'prequalified', 'pre-qualified', 'you qualify', 'approved for',
        ];

        foreach ($forbiddenTerms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
