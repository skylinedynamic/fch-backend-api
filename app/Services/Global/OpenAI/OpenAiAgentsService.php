<?php

namespace App\Services\Global\OpenAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for OpenAI's Agents API (https://platform.openai.com/agents): runs a
 * saved Agent (agent_...) exactly as it is configured on the platform.
 *
 * Instructions, model and reasoning are whatever is saved on the platform. A
 * session copies the saved agent when it is created and never sees later
 * edits, so every chat turn runs in a brand-new session: an edit saved on the
 * platform is used by the very next message, with no deploy and no cache to
 * clear.
 *
 * Tools are the exception. A caller may pass session-only overrides, sent as
 * an inline `agent` object alongside `agent_id`, so knowledge retrieval is
 * guaranteed for the turn instead of depending on the saved agent having been
 * configured with the right file_search store. Per OpenAI's merge rules an
 * override array replaces the saved field rather than adding to it, so a tool
 * list passed must be complete.
 *
 * Requires an API key with the api.agents.read and api.agents.write
 * permissions (plus api.responses.write for the model call itself).
 */
class OpenAiAgentsService extends OpenAiResponsesService
{
    /** Session states in which the turn is still being worked on. */
    private const RUNNING_STATUSES = ['pending', 'in_progress', 'running'];

    private const POLL_INTERVAL_MS = 700;

    /**
     * The saved agent as it is on the platform right now (name, model,
     * instructions, ...). Never cached.
     *
     * `unreachable` separates "OpenAI says this id is wrong" from "we never got
     * to ask". Only the first is evidence against the id; the second is a
     * network fault, and a caller must not treat it as a bad configuration.
     *
     * @return array{ok: bool, agent: ?array, unreachable: bool, error: ?string}
     */
    public function retrieveAgent(string $agentId): array
    {
        try {
            // The same budget the generation itself gets. An agent definition is
            // as large as its instructions — a real underwriting agent runs to
            // tens of kilobytes — and on a slow link a 30-second read times out
            // part-way through the body, which looks like an unreachable agent
            // rather than the slow download it is.
            $response = $this->client($this->timeout)->get("{$this->baseUrl}/agents/{$agentId}");
        } catch (\Throwable $e) {
            return ['ok' => false, 'agent' => null, 'unreachable' => true, 'error' => 'Could not reach OpenAI: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'agent' => null, 'unreachable' => false, 'error' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'agent' => (array) $response->json(), 'unreachable' => false, 'error' => null];
    }

    /**
     * A vector store as it is on the platform (name, file counts, status), so
     * a knowledge base can be verified to exist and be reachable by this key
     * before it is saved against an assistant.
     *
     * @return array{ok: bool, store: ?array, unreachable: bool, error: ?string}
     */
    public function retrieveVectorStore(string $vectorStoreId): array
    {
        try {
            // Vector stores are a Responses-API resource, not an Agents one:
            // no agents beta header, and no /agents prefix on the path.
            $response = Http::timeout(30)
                ->connectTimeout(20)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get("{$this->baseUrl}/vector_stores/{$vectorStoreId}");
        } catch (\Throwable $e) {
            return ['ok' => false, 'store' => null, 'unreachable' => true, 'error' => 'Could not reach OpenAI: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'store' => null, 'unreachable' => false, 'error' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'store' => (array) $response->json(), 'unreachable' => false, 'error' => null];
    }

    /**
     * One chat turn, grounded in the assistant's approved knowledge base.
     *
     * The turn runs on the Responses API rather than as an Agents session,
     * because file_search is a Responses API tool. The Agents API rejects it
     * outright — "Invalid value: 'file_search'. Supported values are:
     * 'function', 'tool_search', 'programmatic_tool_calling', 'mcp',
     * 'web_search', 'computer_use'" — and rejects a tool_choice on the inline
     * agent object with it, so a session could never search a vector store at
     * all. Sending the knowledge base is the whole point of this call, so the
     * call moved to the API that accepts it.
     *
     * The saved agent is still the source of truth for what the assistant says
     * and how it thinks: its instructions, model and reasoning effort are read
     * from the platform on every turn and sent with the request. An edit saved
     * on the platform is used by the very next message, with nothing to deploy
     * and no cache to clear — exactly as before.
     *
     * @param array<int, array{role: string, content: string}> $messages Full turn history, oldest first.
     * @param array<string, string> $metadata Non-PII string key/values stored on the OpenAI response.
     * @param array<string, mixed> $agentOverrides Responses API tools/tool_choice/text, plus
     *                                             `instructions_append`; empty sends no tools.
     *
     * @return array{ok: bool, text: ?string, sources: array, model: ?string, latency_ms: int, usage: ?array, request_id: ?string, error: ?string}
     */
    public function runAgent(string $agentId, array $messages, array $metadata = [], array $logContext = [], array $agentOverrides = []): array
    {
        $startTime = microtime(true);

        $saved = $this->retrieveAgent($agentId);

        if (! $saved['ok']) {
            // An unreachable platform is a transport fault, not a bad agent.
            return $this->agentFailure(
                $logContext,
                ($saved['unreachable'] ?? false) ? 'TRANSPORT_ERROR' : 'API_ERROR',
                $startTime,
                $saved['error']
            );
        }

        $agent = (array) $saved['agent'];
        $model = trim((string) ($agent['model'] ?? ''));

        if ($model === '') {
            return $this->agentFailure($logContext, 'API_ERROR', $startTime, "Agent {$agentId} has no model saved on the platform.");
        }

        $body = [
            'model' => $model,
            'input' => $this->responsesInput($messages),
            'store' => (bool) config('openai.store', true),
        ];

        // The saved instructions, followed by whatever the caller needs the
        // answer to carry for the application itself (a reply format). The
        // agent's own wording always comes first and is never altered.
        $instructions = (string) ($agent['instructions'] ?? '') . (string) ($agentOverrides['instructions_append'] ?? '');

        if (trim($instructions) !== '') {
            $body['instructions'] = $instructions;
        }

        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        // The approved knowledge base, as the caller built it. These are
        // Responses API fields already — a file_search tool and, when search is
        // forced, the tool_choice that makes the model look before answering.
        // `text` carries a structured reply format when the caller needs one.
        foreach (['tools', 'tool_choice', 'text'] as $field) {
            if (! empty($agentOverrides[$field])) {
                $body[$field] = $agentOverrides[$field];
            }
        }

        // Only the reasoning-capable families accept reasoning.effort; sending
        // it to any other model is a hard 400.
        $effort = $agent['reasoning']['effort'] ?? null;

        if (is_string($effort) && $effort !== '' && $this->modelSupportsReasoningEffort($model)) {
            $body['reasoning'] = ['effort' => $effort];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/responses", $body);
        } catch (\Throwable $e) {
            return $this->agentFailure($logContext, 'TRANSPORT_ERROR', $startTime, 'OpenAI Responses API connection error: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->agentFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($response), $response->status());
        }

        $json = (array) $response->json();
        $requestId = is_string($json['id'] ?? null) ? $json['id'] : null;
        $text = $this->extractOutputText($json);

        if ($text === null) {
            return $this->agentFailure($logContext, 'INVALID_JSON', $startTime, 'OpenAI returned no answer text.', $response->status(), $requestId);
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
        $sources = $this->extractCitations($json);
        $searches = $this->countFileSearchCalls((array) ($json['output'] ?? []));

        // Whether the knowledge base was actually consulted, and whether
        // anything it returned was cited. An answer with no search behind
        // it is the model talking from memory — the single most useful
        // thing to know when a reply is confidently wrong.
        $this->logOutcome(array_merge($logContext, [
            'file_search_calls' => $searches,
            'citations' => count($sources),
        ]), $model, 'SUCCESS', $latencyMs, $usage, 200, $requestId);

        return [
            'ok' => true,
            // A structured reply is left as the JSON it is: the caller decodes
            // it and cleans the answer inside, where the markers actually are.
            'text' => empty($agentOverrides['text']) ? $this->stripCitationMarkers($text) : trim($text),
            'sources' => $sources,
            'file_search_calls' => $searches,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            // The response id is what identifies this turn in OpenAI's logs.
            'request_id' => $requestId,
            'error' => null,
        ];
    }

    /**
     * One structured generation driven entirely by a saved agent: the
     * underwriting steps of the Pre-Qualification pipeline.
     *
     * Everything that decides what the answer says is read from the platform
     * on every run and nothing is supplied from this codebase — the agent's
     * instructions, its model, its reasoning effort, and the JSON Schema its
     * output must satisfy, which is the agent's own "Text format". An edit
     * saved on the platform applies to the very next evaluation, with nothing
     * to deploy and no cache to clear. The caller supplies only the facts to
     * underwrite (the borrower, the live lender matrix) and, through
     * $agentOverrides, the knowledge base to search.
     *
     * An agent saved without a JSON Schema text format is refused rather than
     * given one from here: a schema written in this codebase is exactly the
     * hard-coded underwriting contract this path exists to remove, and prose
     * where the pipeline expects a structured result would be stored as an
     * underwriting outcome nobody can read.
     *
     * The decoded output is re-checked locally against that same schema before
     * it is returned, for the reason OpenAiResponsesService::attempt() does:
     * the model's own "strict" flag is not proof its output matched.
     *
     * @param array                $input          Structured payload sent as the user input.
     * @param array<string, mixed> $agentOverrides Responses API tools/tool_choice; empty sends no tools.
     *
     * @return array{ok: bool, data: ?array, model: ?string, latency_ms: int, usage: ?array, request_id: ?string, sources: array, file_search_calls: int, prompt_version: ?string, reasoning_effort: ?string, error: ?string}
     */
    public function runStructuredAgent(string $agentId, array $input, array $agentOverrides = [], array $logContext = []): array
    {
        $startTime = microtime(true);

        $saved = $this->retrieveAgentWithRetries($agentId, $logContext);

        if (! $saved['ok']) {
            // An unreachable platform is a transport fault, not a bad agent.
            return $this->structuredFailure(
                $logContext,
                ($saved['unreachable'] ?? false) ? 'TRANSPORT_ERROR' : 'API_ERROR',
                $startTime,
                $saved['error']
            );
        }

        $agent = (array) $saved['agent'];
        $model = trim((string) ($agent['model'] ?? ''));

        if ($model === '') {
            return $this->structuredFailure($logContext, 'API_ERROR', $startTime, "Agent {$agentId} has no model saved on the platform.");
        }

        $text = is_array($agent['text'] ?? null) ? $agent['text'] : [];
        $format = is_array($text['format'] ?? null) ? $text['format'] : [];
        $schema = is_array($format['schema'] ?? null) ? $format['schema'] : null;

        if (($format['type'] ?? null) !== 'json_schema' || $schema === null) {
            return $this->structuredFailure(
                $logContext,
                'API_ERROR',
                $startTime,
                "Agent {$agentId} is not set up to return a structured result. Set its Text format to JSON Schema on the OpenAI platform."
            );
        }

        // `reasoning.effort` and a non-default `text.verbosity` are only
        // accepted by the reasoning-capable families; sending either to any
        // other model is a hard 400, so they are dropped rather than passed on
        // from the agent unexamined.
        $supportsReasoning = $this->modelSupportsReasoningEffort($model);

        if (! $supportsReasoning) {
            unset($text['verbosity']);
        }

        // The Agents API stores a schema without a name; the Responses API
        // refuses one — "Missing required parameter: 'text.format.name'". The
        // name is only an identifier for the schema, so it is supplied from the
        // agent's own name rather than being something a host has to go and add
        // on the platform before their agent will run at all.
        if (! isset($format['name']) || trim((string) $format['name']) === '') {
            $format['name'] = $this->schemaNameFor($agent, $agentId);
            $text['format'] = $format;
        }

        $body = [
            'model' => $model,
            'store' => (bool) config('openai.store', true),
            'input' => json_encode($input),
            'text' => $text,
        ];

        if (filled($agent['instructions'] ?? null)) {
            $body['instructions'] = (string) $agent['instructions'];
        }

        // The approved knowledge base, as the caller built it.
        foreach (['tools', 'tool_choice'] as $field) {
            if (! empty($agentOverrides[$field])) {
                $body[$field] = $agentOverrides[$field];
            }
        }

        $effort = $agent['reasoning']['effort'] ?? null;
        $effort = is_string($effort) && $effort !== '' ? $effort : null;

        if ($effort !== null && $supportsReasoning) {
            $body['reasoning'] = ['effort' => $effort];
        }

        $maxAttempts = max(1, (int) config('openai.max_attempts', 3));
        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->attemptStructuredAgent($body, $schema, array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
            ]));

            if ($result['ok'] || ! $this->isRetryable($result) || $attempt === $maxAttempts) {
                break;
            }

            // Back off before retrying so a rate limit or a momentary upstream
            // problem is given time to clear rather than being hit again at once.
            $delayMs = (int) config('openai.retry_delay_ms', 2000) * $attempt;

            Log::warning('FCH Agent structured run retrying', array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retry_in_ms' => $delayMs,
                'failure_kind' => $result['failure_kind'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'error' => $result['error'],
            ]));

            usleep($delayMs * 1000);
        }

        // Recorded against the stored result so an evaluation can be traced
        // back to the agent, and the effort, that actually produced it.
        $result['reasoning_effort'] = $effort;
        $result['prompt_version'] = is_string($agent['name'] ?? null) ? $agent['name'] : null;

        return $result;
    }

    /**
     * The saved agent, retried when the platform could not be reached.
     *
     * The generation itself is retried on a transient fault, and reading the
     * agent is just as exposed to one: it is a large download — an underwriting
     * agent's instructions run to tens of kilobytes — so a slow link drops it
     * part-way often enough to matter. Without this, one timed-out read failed
     * the whole evaluation while the retry policy stood unused behind it.
     *
     * Only an unreachable platform is retried. A 404 on the id, or a key that
     * may not read agents, fails identically every time and is returned at once.
     */
    protected function retrieveAgentWithRetries(string $agentId, array $logContext): array
    {
        $maxAttempts = max(1, (int) config('openai.max_attempts', 3));
        $saved = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $saved = $this->retrieveAgent($agentId);

            if ($saved['ok'] || ! ($saved['unreachable'] ?? false) || $attempt === $maxAttempts) {
                break;
            }

            $delayMs = (int) config('openai.retry_delay_ms', 2000) * $attempt;

            Log::warning('FCH Agent read retrying', array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retry_in_ms' => $delayMs,
                'error' => $saved['error'],
            ]));

            usleep($delayMs * 1000);
        }

        return $saved;
    }

    /**
     * A name for the agent's schema, for the Responses API field that requires
     * one. It identifies the schema in the request and in OpenAI's logs; it
     * does not affect what the model returns.
     *
     * Taken from the agent's own name so the two line up when a run is looked
     * up later — "Prequalification Agent" becomes "prequalification_agent".
     * OpenAI accepts letters, digits, underscores and dashes only, so anything
     * else is folded to an underscore, and an agent with no usable name falls
     * back to its id.
     */
    protected function schemaNameFor(array $agent, string $agentId): string
    {
        $name = strtolower(trim((string) ($agent['name'] ?? '')));
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');

        if ($name === '') {
            $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $agentId), '_');
        }

        return substr($name === '' ? 'agent_schema' : $name, 0, 64);
    }

    /**
     * One attempt at a structured generation. Shaped exactly like
     * OpenAiResponsesService::attempt()'s return so a caller cannot tell which
     * of the two produced its result.
     */
    protected function attemptStructuredAgent(array $body, array $schema, array $logContext): array
    {
        $startTime = microtime(true);
        $model = (string) $body['model'];

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/responses", $body);
        } catch (\Throwable $e) {
            return $this->structuredFailure($logContext, 'TRANSPORT_ERROR', $startTime, 'OpenAI Responses API connection error: ' . $e->getMessage(), $model);
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $requestId = $response->header('x-request-id') ?: null;

        if (! $response->successful()) {
            return $this->structuredFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($response), $model, $response->status(), $requestId);
        }

        $json = (array) $response->json();
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
        // OpenAI's id for this call: the response id, falling back to the
        // request header for a response that carried none.
        $requestId = is_string($json['id'] ?? null) ? $json['id'] : $requestId;

        $outputText = $this->extractOutputText($json);
        $decoded = $outputText !== null ? json_decode($outputText, true) : null;

        if (! is_array($decoded)) {
            return $this->structuredFailure($logContext, 'INVALID_JSON', $startTime, 'The agent returned no parseable structured output.', $model, $response->status(), $requestId, $usage);
        }

        // Whatever the knowledge base contributed is kept; the markers naming
        // the files it came from are not. See stripFileCitations().
        $decoded = $this->stripFileCitations($decoded);

        // The agent's own schema, re-checked here: never trust the model's
        // "strict" flag as proof its output actually matched it.
        $schemaErrors = $this->validateAgainstSchema($decoded, $schema);

        if (! empty($schemaErrors)) {
            return $this->structuredFailure(
                $logContext,
                'SCHEMA_VALIDATION_FAILED',
                $startTime,
                "The agent's output failed local schema validation: " . implode('; ', $schemaErrors),
                $model,
                $response->status(),
                $requestId,
                $usage
            );
        }

        $sources = $this->extractCitations($json);
        $searches = $this->countFileSearchCalls((array) ($json['output'] ?? []));

        // Whether the knowledge base was actually consulted. Zero searches on a
        // step that was given one means the evaluation was reasoned without
        // reading a single approved file.
        $this->logOutcome(array_merge($logContext, [
            'file_search_calls' => $searches,
            'citations' => count($sources),
        ]), $model, 'SUCCESS', $latencyMs, $usage, $response->status(), $requestId);

        return [
            'ok' => true,
            'data' => $decoded,
            'model' => is_string($json['model'] ?? null) ? $json['model'] : $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'request_id' => $requestId,
            'sources' => $sources,
            'file_search_calls' => $searches,
            'error' => null,
        ];
    }

    /**
     * Remove file_search citation markers from every string in the result.
     *
     * When a step searches a knowledge base, the model writes its citations
     * inline as markers like "【4:0†Lender_Matrix_Internal_v2.md】". In a chat
     * reply those are rendered as footnotes. In a stored underwriting result
     * they are two problems at once: the marker itself is shown verbatim to a
     * borrower in the middle of a sentence, and it names an internal knowledge
     * file — which is exactly the kind of internal detail the borrower-safe
     * generation exists to keep out.
     *
     * Only the marker is removed; the sentence the model wrote around it is
     * untouched, and the citations themselves are still reported separately in
     * `sources` for the log. Double spaces left behind by a removed marker are
     * closed up so the text reads as written.
     */
    protected function stripFileCitations(array $data): array
    {
        array_walk_recursive($data, static function (&$value) {
            if (! is_string($value) || $value === '') {
                return;
            }

            // 【...】 is OpenAI's marker; the ASCII form appears in some models'
            // output for the same thing.
            $cleaned = preg_replace('/【[^】]*】|\x{3010}[^\x{3011}]*\x{3011}/u', '', $value) ?? $value;
            $cleaned = preg_replace('/\[\d+:\d+†[^\]]*\]/u', '', $cleaned) ?? $cleaned;

            // Only tidy up when something was actually removed, so untouched
            // text keeps whatever spacing the model intended.
            if ($cleaned !== $value) {
                $cleaned = preg_replace('/ {2,}/', ' ', $cleaned) ?? $cleaned;
                $cleaned = preg_replace('/\s+([.,;:!?])/u', '$1', $cleaned) ?? $cleaned;
                $value = trim($cleaned);
            }
        });

        return $data;
    }

    private function structuredFailure(
        array $logContext,
        string $kind,
        float $startTime,
        ?string $error,
        ?string $model = null,
        ?int $httpStatus = null,
        ?string $requestId = null,
        ?array $usage = null
    ): array {
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->logOutcome($logContext, (string) $model, $kind, $latencyMs, $usage, $httpStatus, $requestId);

        return [
            'ok' => false,
            'data' => null,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'request_id' => $requestId,
            'sources' => [],
            'file_search_calls' => 0,
            // Nothing ran, so there is no agent provenance to record. Present
            // but null, so a caller reads the same keys from every outcome.
            'prompt_version' => null,
            'reasoning_effort' => null,
            'failure_kind' => $kind,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }

    /**
     * The answer without the model's inline citation markers.
     *
     * A file_search answer marks each cited passage in the text itself, as
     * "filecite", "turn0file1", ... wrapped in private-use Unicode characters
     * (U+E200–U+E206). They are placeholders for a client to swap for a
     * rendered citation; shown as they are, they read as boxes and internal
     * file references. What was cited is not lost: it is in the response's
     * annotations, which extractCitations() returns as `sources`.
     */
    public function stripCitationMarkers(string $text): string
    {
        return self::withoutCitationMarkers($text);
    }

    /**
     * The same clean-up for text that is already stored — a reply saved before
     * the markers were removed on arrival must not show them either.
     */
    public static function withoutCitationMarkers(string $text): string
    {
        $clean = preg_replace('/\x{E200}[^\x{E201}]*\x{E201}/u', '', $text) ?? $text;
        // A marker cut short (no closing character) still must not show.
        $clean = preg_replace('/[\x{E200}-\x{E206}]/u', '', $clean) ?? $clean;
        // Removing a marker can leave its leading space before punctuation or a line end.
        $clean = preg_replace('/[ \t]+(?=[.,;:!?]|\R|$)/u', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * One structured generation driven entirely by a saved agent: the
     * underwriting steps of the Pre-Qualification pipeline.
     *
     * Everything that decides what the answer says is read from the platform
     * on every run and nothing is supplied from this codebase — the agent's
     * instructions, its model, its reasoning effort, and the JSON Schema its
     * output must satisfy, which is the agent's own "Text format". An edit
     * saved on the platform applies to the very next evaluation, with nothing
     * to deploy and no cache to clear. The caller supplies only the facts to
     * underwrite (the borrower, the live lender matrix) and, through
     * $agentOverrides, the knowledge base to search.
     *
     * An agent saved without a JSON Schema text format is refused rather than
     * given one from here: a schema written in this codebase is exactly the
     * hard-coded underwriting contract this path exists to remove, and prose
     * where the pipeline expects a structured result would be stored as an
     * underwriting outcome nobody can read.
     *
     * The decoded output is re-checked locally against that same schema before
     * it is returned, for the reason OpenAiResponsesService::attempt() does:
     * the model's own "strict" flag is not proof its output matched.
     *
     * @param array                $input          Structured payload sent as the user input.
     * @param array<string, mixed> $agentOverrides Responses API tools/tool_choice; empty sends no tools.
     *
     * @return array{ok: bool, data: ?array, model: ?string, latency_ms: int, usage: ?array, request_id: ?string, sources: array, file_search_calls: int, prompt_version: ?string, reasoning_effort: ?string, error: ?string}
     */
    public function runStructuredAgent(string $agentId, array $input, array $agentOverrides = [], array $logContext = []): array
    {
        $startTime = microtime(true);

        $saved = $this->retrieveAgentWithRetries($agentId, $logContext);

        if (! $saved['ok']) {
            // An unreachable platform is a transport fault, not a bad agent.
            return $this->structuredFailure(
                $logContext,
                ($saved['unreachable'] ?? false) ? 'TRANSPORT_ERROR' : 'API_ERROR',
                $startTime,
                $saved['error']
            );
        }

        $agent = (array) $saved['agent'];
        $model = trim((string) ($agent['model'] ?? ''));

        if ($model === '') {
            return $this->structuredFailure($logContext, 'API_ERROR', $startTime, "Agent {$agentId} has no model saved on the platform.");
        }

        $text = is_array($agent['text'] ?? null) ? $agent['text'] : [];
        $format = is_array($text['format'] ?? null) ? $text['format'] : [];
        $schema = is_array($format['schema'] ?? null) ? $format['schema'] : null;

        if (($format['type'] ?? null) !== 'json_schema' || $schema === null) {
            return $this->structuredFailure(
                $logContext,
                'API_ERROR',
                $startTime,
                "Agent {$agentId} is not set up to return a structured result. Set its Text format to JSON Schema on the OpenAI platform."
            );
        }

        // `reasoning.effort` and a non-default `text.verbosity` are only
        // accepted by the reasoning-capable families; sending either to any
        // other model is a hard 400, so they are dropped rather than passed on
        // from the agent unexamined.
        $supportsReasoning = $this->modelSupportsReasoningEffort($model);

        if (! $supportsReasoning) {
            unset($text['verbosity']);
        }

        // The Agents API stores a schema without a name; the Responses API
        // refuses one — "Missing required parameter: 'text.format.name'". The
        // name is only an identifier for the schema, so it is supplied from the
        // agent's own name rather than being something a host has to go and add
        // on the platform before their agent will run at all.
        if (! isset($format['name']) || trim((string) $format['name']) === '') {
            $format['name'] = $this->schemaNameFor($agent, $agentId);
            $text['format'] = $format;
        }

        $body = [
            'model' => $model,
            'store' => (bool) config('openai.store', true),
            'input' => json_encode($input),
            'text' => $text,
        ];

        if (filled($agent['instructions'] ?? null)) {
            $body['instructions'] = (string) $agent['instructions'];
        }

        // The approved knowledge base, as the caller built it.
        foreach (['tools', 'tool_choice'] as $field) {
            if (! empty($agentOverrides[$field])) {
                $body[$field] = $agentOverrides[$field];
            }
        }

        $effort = $agent['reasoning']['effort'] ?? null;
        $effort = is_string($effort) && $effort !== '' ? $effort : null;

        if ($effort !== null && $supportsReasoning) {
            $body['reasoning'] = ['effort' => $effort];
        }

        $maxAttempts = max(1, (int) config('openai.max_attempts', 3));
        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->attemptStructuredAgent($body, $schema, array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
            ]));

            if ($result['ok'] || ! $this->isRetryable($result) || $attempt === $maxAttempts) {
                break;
            }

            // Back off before retrying so a rate limit or a momentary upstream
            // problem is given time to clear rather than being hit again at once.
            $delayMs = (int) config('openai.retry_delay_ms', 2000) * $attempt;

            Log::warning('FCH Agent structured run retrying', array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retry_in_ms' => $delayMs,
                'failure_kind' => $result['failure_kind'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'error' => $result['error'],
            ]));

            usleep($delayMs * 1000);
        }

        // Recorded against the stored result so an evaluation can be traced
        // back to the agent, and the effort, that actually produced it.
        $result['reasoning_effort'] = $effort;
        $result['prompt_version'] = is_string($agent['name'] ?? null) ? $agent['name'] : null;

        return $result;
    }

    /**
     * The saved agent, retried when the platform could not be reached.
     *
     * The generation itself is retried on a transient fault, and reading the
     * agent is just as exposed to one: it is a large download — an underwriting
     * agent's instructions run to tens of kilobytes — so a slow link drops it
     * part-way often enough to matter. Without this, one timed-out read failed
     * the whole evaluation while the retry policy stood unused behind it.
     *
     * Only an unreachable platform is retried. A 404 on the id, or a key that
     * may not read agents, fails identically every time and is returned at once.
     */
    protected function retrieveAgentWithRetries(string $agentId, array $logContext): array
    {
        $maxAttempts = max(1, (int) config('openai.max_attempts', 3));
        $saved = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $saved = $this->retrieveAgent($agentId);

            if ($saved['ok'] || ! ($saved['unreachable'] ?? false) || $attempt === $maxAttempts) {
                break;
            }

            $delayMs = (int) config('openai.retry_delay_ms', 2000) * $attempt;

            Log::warning('FCH Agent read retrying', array_merge($logContext, [
                'agent_id' => $agentId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retry_in_ms' => $delayMs,
                'error' => $saved['error'],
            ]));

            usleep($delayMs * 1000);
        }

        return $saved;
    }

    /**
     * A name for the agent's schema, for the Responses API field that requires
     * one. It identifies the schema in the request and in OpenAI's logs; it
     * does not affect what the model returns.
     *
     * Taken from the agent's own name so the two line up when a run is looked
     * up later — "Prequalification Agent" becomes "prequalification_agent".
     * OpenAI accepts letters, digits, underscores and dashes only, so anything
     * else is folded to an underscore, and an agent with no usable name falls
     * back to its id.
     */
    protected function schemaNameFor(array $agent, string $agentId): string
    {
        $name = strtolower(trim((string) ($agent['name'] ?? '')));
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');

        if ($name === '') {
            $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $agentId), '_');
        }

        return substr($name === '' ? 'agent_schema' : $name, 0, 64);
    }

    /**
     * One attempt at a structured generation. Shaped exactly like
     * OpenAiResponsesService::attempt()'s return so a caller cannot tell which
     * of the two produced its result.
     */
    protected function attemptStructuredAgent(array $body, array $schema, array $logContext): array
    {
        $startTime = microtime(true);
        $model = (string) $body['model'];

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/responses", $body);
        } catch (\Throwable $e) {
            return $this->structuredFailure($logContext, 'TRANSPORT_ERROR', $startTime, 'OpenAI Responses API connection error: ' . $e->getMessage(), $model);
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $requestId = $response->header('x-request-id') ?: null;

        if (! $response->successful()) {
            return $this->structuredFailure($logContext, 'API_ERROR', $startTime, $this->errorMessage($response), $model, $response->status(), $requestId);
        }

        $json = (array) $response->json();
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
        // OpenAI's id for this call: the response id, falling back to the
        // request header for a response that carried none.
        $requestId = is_string($json['id'] ?? null) ? $json['id'] : $requestId;

        $outputText = $this->extractOutputText($json);
        $decoded = $outputText !== null ? json_decode($outputText, true) : null;

        if (! is_array($decoded)) {
            return $this->structuredFailure($logContext, 'INVALID_JSON', $startTime, 'The agent returned no parseable structured output.', $model, $response->status(), $requestId, $usage);
        }

        // Whatever the knowledge base contributed is kept; the markers naming
        // the files it came from are not. See stripFileCitations().
        $decoded = $this->stripFileCitations($decoded);

        // The agent's own schema, re-checked here: never trust the model's
        // "strict" flag as proof its output actually matched it.
        $schemaErrors = $this->validateAgainstSchema($decoded, $schema);

        if (! empty($schemaErrors)) {
            return $this->structuredFailure(
                $logContext,
                'SCHEMA_VALIDATION_FAILED',
                $startTime,
                "The agent's output failed local schema validation: " . implode('; ', $schemaErrors),
                $model,
                $response->status(),
                $requestId,
                $usage
            );
        }

        $sources = $this->extractCitations($json);
        $searches = $this->countFileSearchCalls((array) ($json['output'] ?? []));

        // Whether the knowledge base was actually consulted. Zero searches on a
        // step that was given one means the evaluation was reasoned without
        // reading a single approved file.
        $this->logOutcome(array_merge($logContext, [
            'file_search_calls' => $searches,
            'citations' => count($sources),
        ]), $model, 'SUCCESS', $latencyMs, $usage, $response->status(), $requestId);

        return [
            'ok' => true,
            'data' => $decoded,
            'model' => is_string($json['model'] ?? null) ? $json['model'] : $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'request_id' => $requestId,
            'sources' => $sources,
            'file_search_calls' => $searches,
            'error' => null,
        ];
    }

    /**
     * Remove file_search citation markers from every string in the result.
     *
     * When a step searches a knowledge base, the model writes its citations
     * inline as markers like "【4:0†Lender_Matrix_Internal_v2.md】". In a chat
     * reply those are rendered as footnotes. In a stored underwriting result
     * they are two problems at once: the marker itself is shown verbatim to a
     * borrower in the middle of a sentence, and it names an internal knowledge
     * file — which is exactly the kind of internal detail the borrower-safe
     * generation exists to keep out.
     *
     * Only the marker is removed; the sentence the model wrote around it is
     * untouched, and the citations themselves are still reported separately in
     * `sources` for the log. Double spaces left behind by a removed marker are
     * closed up so the text reads as written.
     */
    protected function stripFileCitations(array $data): array
    {
        array_walk_recursive($data, static function (&$value) {
            if (! is_string($value) || $value === '') {
                return;
            }

            // 【...】 is OpenAI's marker; the ASCII form appears in some models'
            // output for the same thing.
            $cleaned = preg_replace('/【[^】]*】|\x{3010}[^\x{3011}]*\x{3011}/u', '', $value) ?? $value;
            $cleaned = preg_replace('/\[\d+:\d+†[^\]]*\]/u', '', $cleaned) ?? $cleaned;

            // Only tidy up when something was actually removed, so untouched
            // text keeps whatever spacing the model intended.
            if ($cleaned !== $value) {
                $cleaned = preg_replace('/ {2,}/', ' ', $cleaned) ?? $cleaned;
                $cleaned = preg_replace('/\s+([.,;:!?])/u', '$1', $cleaned) ?? $cleaned;
                $value = trim($cleaned);
            }
        });

        return $data;
    }

    private function structuredFailure(
        array $logContext,
        string $kind,
        float $startTime,
        ?string $error,
        ?string $model = null,
        ?int $httpStatus = null,
        ?string $requestId = null,
        ?array $usage = null
    ): array {
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->logOutcome($logContext, (string) $model, $kind, $latencyMs, $usage, $httpStatus, $requestId);

        return [
            'ok' => false,
            'data' => null,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'request_id' => $requestId,
            'sources' => [],
            'file_search_calls' => 0,
            // Nothing ran, so there is no agent provenance to record. Present
            // but null, so a caller reads the same keys from every outcome.
            'prompt_version' => null,
            'reasoning_effort' => null,
            'failure_kind' => $kind,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }

    /**
     * The conversation as Responses API input.
     *
     * Unlike an Agents session — which accepts only "user" and so needed the
     * history flattened into one message — the Responses API takes the turns
     * as they happened, which is what lets the model see its own earlier
     * replies rather than a transcript of them. Input and output text are
     * typed differently, so each turn carries the type its role requires.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<int, array{role: string, content: array<int, array{type: string, text: string}>}>
     */
    private function responsesInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $text = trim((string) ($message['content'] ?? ''));

            if ($text === '') {
                continue;
            }

            $isAssistant = ($message['role'] ?? 'user') === 'assistant';

            $input[] = [
                'role' => $isAssistant ? 'assistant' : 'user',
                'content' => [[
                    'type' => $isAssistant ? 'output_text' : 'input_text',
                    'text' => $text,
                ]],
            ];
        }

        return $input;
    }

    /**
     * Reads a session-create reply. A streamed reply (server-sent events)
     * yields the session as of its last event plus every finished item of the
     * turn; a plain JSON reply yields just the session, with items left null
     * for the caller to fetch.
     *
     * @return array{session: array, items: ?array}
     */
    protected function readTurn($response): array
    {
        $body = (string) $response->body();

        if (! str_contains((string) $response->header('Content-Type'), 'text/event-stream') && ! preg_match('/^\s*(event|data):/', $body)) {
            return ['session' => (array) $response->json(), 'items' => null];
        }

        $session = [];
        $items = [];

        foreach (preg_split('/\R{2,}/', trim($body)) as $block) {
            $payload = '';
            foreach (preg_split('/\R/', $block) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $payload .= ltrim(substr($line, 5));
                }
            }

            $event = json_decode($payload, true);
            if (! is_array($event)) {
                continue;
            }

            if (is_array($event['session'] ?? null)) {
                $session = $event['session'];
            }

            $type = (string) ($event['type'] ?? '');

            if ($type === 'agent.session.turn.item.done' && is_array($event['item'] ?? null)) {
                $items[] = $event['item'];
            } elseif (in_array($type, ['agent.session.turn.failed', 'agent.session.turn.cancelled'], true)) {
                $session['status'] = str_ends_with($type, 'failed') ? 'failed' : 'cancelled';
            }
        }

        return ['session' => $session, 'items' => $items];
    }

    /**
     * How many times the agent searched the knowledge base during this turn.
     * Zero means file_search never ran, so nothing in the answer came from an
     * approved knowledge file.
     */
    protected function countFileSearchCalls(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (is_array($item) && str_contains((string) ($item['type'] ?? ''), 'file_search')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The agent's reply: its message marked as the final answer, or — when no
     * message carries a phase — its last message.
     */
    protected function extractFinalAnswer(array $items): ?string
    {
        $last = null;
        $final = null;

        foreach ($items as $item) {
            if (! is_array($item) || ($item['role'] ?? null) !== 'assistant') {
                continue;
            }

            $text = $this->itemText($item['content'] ?? null);
            if ($text === '') {
                continue;
            }

            $last = $text;
            if (($item['phase'] ?? null) === 'final_answer') {
                $final = $text;
            }
        }

        return $final ?? $last;
    }

    /**
     * A new session accepts user messages only — an earlier assistant reply
     * cannot be sent back as its own message. So a first message goes as it
     * is, and a follow-up carries the earlier turns as a transcript ahead of
     * the new message, which is what keeps a fresh session per turn (and so
     * real-time agent updates) compatible with a multi-turn conversation.
     */
    protected function asSingleUserMessage(array $messages): string
    {
        $current = array_pop($messages);
        $text = (string) ($current['content'] ?? '');

        if (empty($messages)) {
            return $text;
        }

        $transcript = implode("\n\n", array_map(
            static fn (array $m) => ($m['role'] === 'assistant' ? 'Assistant' : 'User') . ': ' . $m['content'],
            $messages
        ));

        return "Earlier in this conversation:\n\n{$transcript}\n\nNew message from the user:\n\n{$text}";
    }

    private function itemText($content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        $parts = [];
        foreach ((array) $content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function client(int $timeout): PendingRequest
    {
        // Laravel's 10-second default connect timeout is short for a host that
        // reaches OpenAI through a proxy or a slow link, and a connect failure
        // there is indistinguishable from a bad id at the call site.
        return Http::timeout($timeout)->connectTimeout(20)->withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'OpenAI-Beta' => 'agents=v1',
        ]);
    }

    private function errorMessage($response): string
    {
        $message = $response->json()['error']['message'] ?? $response->body();

        return "OpenAI API error ({$response->status()}): {$message}";
    }

    private function agentFailure(array $logContext, string $kind, float $startTime, string $error, ?int $httpStatus = null, ?string $sessionId = null): array
    {
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->logOutcome($logContext, '', $kind, $latencyMs, null, $httpStatus, $sessionId);

        return [
            'ok' => false,
            'text' => null,
            'sources' => [],
            'file_search_calls' => 0,
            'model' => null,
            'latency_ms' => $latencyMs,
            'usage' => null,
            'failure_kind' => $kind,
            'http_status' => $httpStatus,
            'request_id' => $sessionId,
            'error' => $error,
        ];
    }
}
