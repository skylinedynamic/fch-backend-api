<?php

namespace App\Services\FCH\Common\PreQualification\AUE;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Lender\Lender;
use App\Services\Global\OpenAI\OpenAiResponsesService;

/**
 * FCH_Internal_Prequalification_AUE_v3 — internal-only underwriting evaluation.
 *
 * Grounds every eligibility judgment in the CURRENT active lender matrix pulled from
 * the database at call time (never hard-coded in the prompt). Output is stored as an
 * "internal" row in pre_qualification_gpt_results and never released to the
 * borrower/partner.
 *
 * Every underwriting judgment here comes from the model response. When the call
 * fails this returns ok:false and stores nothing — it never substitutes a locally
 * computed result, because a hard-coded eligibility rule would be presented as an
 * underwriting outcome without any underwriting having taken place.
 */
class PreQualificationInternalAueService
{
    public const PROMPT_VERSION = 'FCH_Internal_Prequalification_AUE_v3';

    protected OpenAiResponsesService $client;

    /** Correlates this run's internal and client rows. */
    protected ?string $runId = null;

    /** OpenAI's identifiers for the call behind the row about to be written. */
    protected ?string $requestId = null;

    public function __construct(OpenAiResponsesService $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{ok: bool, data: ?array, is_fallback: bool, model: ?string, latency_ms: int, error: ?string}
     */
    public function evaluate(PreQualificationRequest $application, ?int $userId = null, ?string $runId = null): array
    {
        $this->runId = $runId;
        $lenderMatrix = $this->buildActiveLenderMatrix();
        $matrixVersion = config('openai.lender_matrix_version', 'runtime') . ':' . now()->format('Y-m-d');

        if (!$this->client->hasApiKey()) {
            return $this->failed('No OpenAI API key configured.');
        }

        $instructions = file_get_contents(__DIR__ . '/FCH_Internal_Prequalification_AUE_v3_Responses_Instructions.txt');
        $schema = json_decode(file_get_contents(__DIR__ . '/FCH_Internal_Prequalification_AUE_v3_Response_Schema.json'), true);

        // submission_id and matrix_version are required by the v3 schema and are
        // ours to state, not the model's to infer, so they are supplied as input
        // and checked against the response below.
        $input = [
            'submission_id' => (string) $application->id,
            'matrix_version' => $matrixVersion,
            'borrower' => $this->buildBorrowerPayload($application),
            'active_lender_matrix' => $lenderMatrix,
            'fch_rule_package' => $this->buildRulePackage(),
        ];

        $result = $this->client->run(
            model: $this->client->defaultModel(),
            instructions: $instructions,
            input: $input,
            schemaName: 'fch_internal_prequalification_aue_v3',
            schema: $schema,
            reasoningEffort: $this->client->defaultReasoningEffort(),
            logContext: [
                'prompt_version' => self::PROMPT_VERSION,
                'pre_qualification_request_id' => $application->id,
                'step' => 'internal_aue',
                'lender_matrix_version' => $matrixVersion,
            ],
        );

        if (!$result['ok']) {
            return $this->failed($result['error']);
        }

        // Carried to persist() so the stored row points at the exact OpenAI call.
        $this->requestId = $result['request_id'] ?? null;

        $data = $result['data'];

        // A lender the model named but that is not in the active, ISO-eligible
        // matrix is dropped rather than shown to staff as routable.
        $activeLenderNames = collect($lenderMatrix)->pluck('lender_name')->unique()->all();
        $data['active_lender_matches'] = collect((array) ($data['active_lender_matches'] ?? []))
            ->filter(fn ($match) => in_array($match['lender_name'] ?? null, $activeLenderNames, true))
            ->values()
            ->all();

        // The routing recommendation names one of those lenders, so it is scrubbed
        // on the same rule — otherwise dropping the match above would leave the
        // recommendation pointing at a lender no longer offered.
        $primaryLender = $data['routing_recommendation']['primary_lender'] ?? null;
        if ($primaryLender !== null && !in_array($primaryLender, $activeLenderNames, true)) {
            $data['routing_recommendation']['primary_lender'] = null;
        }

        // Ours to state, not the model's: keep the identifiers authoritative even
        // if the response echoed them back changed.
        $data['submission_id'] = (string) $application->id;
        $data['matrix_version'] = $matrixVersion;

        $this->persist($application, $data, self::PROMPT_VERSION, $matrixVersion, $result['model'], 'medium', $result['latency_ms'], false, $userId);

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
     * No result. Nothing is persisted, so the application is left without an
     * internal evaluation rather than with a locally invented one.
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

    protected function persist(
        PreQualificationRequest $application,
        array $data,
        string $promptVersion,
        string $matrixVersion,
        ?string $model,
        string $reasoningEffort,
        int $latencyMs,
        bool $isPlaceholder,
        ?int $userId
    ): void {
        PreQualificationGptResult::create([
            'pre_qualification_request_id' => $application->id,
            'type' => PreQualificationGptResult::TYPE_INTERNAL,
            'run_id' => $this->runId,
            'prompt_version' => $promptVersion,
            'matrix_version' => $matrixVersion,
            'model' => $model,
            'openai_request_id' => $this->requestId,
            'reasoning_effort' => $reasoningEffort,
            'payload' => $data,
            'is_placeholder' => $isPlaceholder,
            'latency_ms' => $latencyMs,
            'created_by' => $userId,
        ]);
    }

    /**
     * Pull the current active, ISO-eligible lender matrix as runtime data.
     * Inactive lenders and lenders without an active ISO status are excluded here,
     * so they can never reach the model as eligible options.
     */
    protected function buildActiveLenderMatrix(): array
    {
        $lenders = Lender::query()
            ->where('status', Lender::STATUS_ACTIVE)
            ->where('iso_status', Lender::STATUS_ACTIVE)
            ->with(['visibilities.productCategory'])
            ->get();

        $matrix = [];

        foreach ($lenders as $lender) {
            foreach ($lender->visibilities as $visibility) {
                $entry = array_filter([
                    'lender_name' => $lender->name,
                    'product_category' => $visibility->productCategory->name ?? 'General',
                    'min_fico' => $visibility->min_fico,
                    'min_business_credit_score' => $visibility->min_business_credit_score,
                    'min_tib_months' => $visibility->min_tib_months,
                    'min_monthly_revenue' => $visibility->min_monthly_revenue,
                    'allowed_states' => array_values(array_filter((array) $visibility->allowed_states)) ?: null,
                    'restricted_states' => array_values(array_filter((array) $visibility->restricted_states)) ?: null,
                    'allowed_industries' => array_values(array_filter((array) $visibility->allowed_industries)) ?: null,
                    'restricted_industries' => array_values(array_filter((array) $visibility->restricted_industries)) ?: null,
                ], fn ($value) => $value !== null);

                $matrix[] = $entry;
            }
        }

        return $matrix;
    }

    /**
     * The current FCH underwriting rule package (config/funding_qualification.php),
     * trimmed to the comparison rules that are actually evaluable from prequalification
     * data (personal/business credit, revenue, time in business, state/industry
     * eligibility). The bank-statement-derived rules (NSF, average balance, deposit
     * counts) are omitted — that data does not exist yet at this stage.
     */
    protected function buildRulePackage(): array
    {
        $rules = config('funding_qualification.rules', []);
        $prequalEvaluableKeys = [
            'personal_credit',
            'business_credit',
            'revenue',
            'time_in_business',
            'allowed_states',
            'restricted_states',
            'allowed_industries',
            'restricted_industries',
        ];

        return collect($rules)
            ->only($prequalEvaluableKeys)
            ->toArray();
    }

    /**
     * The borrower profile sent to the model.
     *
     * A field the borrower did not supply is sent as null, never as a substitute
     * value. A placeholder like "Working Capital" or a zero revenue is
     * indistinguishable from a real answer, so the model would underwrite against
     * a figure nobody provided. null says "not provided", and the model raises it
     * as a risk flag instead of quietly assuming it.
     */
    protected function buildBorrowerPayload(PreQualificationRequest $application): array
    {
        $fullName = trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
        $numeric = static fn ($value) => $value === null || $value === '' ? null : (float) $value;

        return [
            'full_name' => $fullName ?: null,
            'email' => $application->email ?: ($application->borrower?->email ?: null),
            'business_name' => $application->business_name ?: null,
            'entity_type' => $application->business_structure ?: null,
            'industry' => $application->industry_type ?: null,
            'state' => $application->state_of_incorporation ?: null,
            'time_in_business' => $application->resolvedYearsInBusiness() ?: null,
            'monthly_revenue' => $numeric($application->monthly_revenue),
            'annual_revenue' => $numeric($application->annual_revenue),
            'credit_score' => $application->personal_credit_score ?: null,
            'credit_score_source' => $application->credit_score_source ?: null,
            'business_credit_score' => $application->estimated_business_credit_score ?: null,
            'funding_purpose' => $application->purpose_of_funding ?: null,
            'amount_requested' => $numeric($application->funding_requested_amount),
            // Left null rather than defaulted to false: "no debt disclosed" and
            // "the question was never answered" are different underwriting facts.
            'has_current_debt' => $application->existing_business_debt === null
                ? null
                : (bool) $application->existing_business_debt,
            'debt_balance' => $numeric($application->debt_balance),
            'notes' => $application->notes ?: ($application->purpose_note ?: null),
            'validator_warnings' => collect((array) $application->validation_issues)
                ->where('severity', '!=', 'INFO')
                ->pluck('internal_message')
                ->values()
                ->all(),
        ];
    }
}
