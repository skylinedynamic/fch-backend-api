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
        $sanitizedInput = [
            'business_name' => $application->business_name,
            'funding_purpose' => $application->purpose_of_funding,
            'amount_requested' => (float) ($application->funding_requested_amount ?? 0),
            'qualified_programs' => (array) ($internalResult['qualified_programs'] ?? []),
            'document_checklist' => (array) ($internalResult['document_checklist'] ?? []),
        ];

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
        $haystack = strtolower(implode(' ', array_filter([
            $data['borrower_summary'] ?? '',
            implode(' ', (array) ($data['qualified_programs'] ?? [])),
            implode(' ', (array) ($data['document_checklist'] ?? [])),
            implode(' ', (array) ($data['next_steps'] ?? [])),
        ])));

        $lenderNames = Lender::query()->pluck('name')->filter()->map(fn ($name) => strtolower($name));
        foreach ($lenderNames as $name) {
            if ($name !== '' && str_contains($haystack, $name)) {
                return true;
            }
        }

        $internalTerms = ['aus model', 'mca aus', 'iso routing', 'risk flag', 'internal_summary', 'underwriting memo'];
        foreach ($internalTerms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
