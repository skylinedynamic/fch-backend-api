<?php

namespace App\Services\Global\OpenAI;

use App\Models\Common\AppConfiguration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around OpenAI's Responses API (POST /v1/responses).
 *
 * Lives outside the PreQualification namespace because it is a general OpenAI
 * client: it knows about credentials, models and the Responses contract, not
 * about underwriting. Currently used by every FCH Pre-Qualification AI step
 * (data integrity validator, internal AUE, client AUE).
 *
 * Callers are responsible for building borrower-safe vs internal-only payloads —
 * this class only knows how to make the call, enforce store:false / structured
 * output, and log request metadata without ever logging payload content.
 */
class OpenAiResponsesService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        // The credential lives in the database (app_configurations, module "openai"),
        // set through Settings > OpenAI Configuration where it is validated and
        // stored encrypted. There is no .env fallback: one source of truth, and the
        // key can be rotated from the admin panel without a redeploy.
        $this->apiKey = self::decryptSecret(self::setting('openai_api_key', ''));
        $this->baseUrl = 'https://api.openai.com/v1';
        $this->timeout = 90;
    }

    public function hasApiKey(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * The model used for every AUE/validator call, host-configurable in Settings.
     */
    public function defaultModel(): string
    {
        return self::setting('openai_aue_model', config('openai.default_model', 'gpt-5.6-luna'));
    }

    /**
     * Reasoning effort for the internal underwriting generation, host-configurable.
     * Ignored automatically for models that do not support it.
     */
    public function defaultReasoningEffort(): string
    {
        $effort = self::setting('openai_reasoning_effort', 'medium');
        $allowed = array_column((array) config('openai.reasoning_efforts', []), 'value');

        return in_array($effort, $allowed, true) ? $effort : 'medium';
    }

    /**
     * The model ids available to this API key, for the admin Settings dropdown.
     * Only models usable for the AUE/validator calls are returned.
     *
     * @return array{ok: bool, models: string[], error: ?string}
     */
    public function listModels(): array
    {
        if (!$this->hasApiKey()) {
            return $this->fallbackModels('No OpenAI API key configured.');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get("{$this->baseUrl}/models");
        } catch (\Throwable $e) {
            return $this->fallbackModels('Could not reach OpenAI: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            $message = $response->json()['error']['message'] ?? $response->body();

            return $this->fallbackModels("OpenAI error ({$response->status()}): {$message}");
        }

        $models = collect($response->json()['data'] ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && preg_match(config('openai.model_include_pattern'), $id))
            // Exclude non-text endpoints that cannot serve a Responses API call.
            ->reject(fn ($id) => preg_match(config('openai.model_exclude_pattern'), $id))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($models)) {
            return $this->fallbackModels('OpenAI returned no usable models for this key.');
        }

        return ['ok' => true, 'models' => $models, 'is_fallback' => false, 'error' => null];
    }

    /**
     * The configured fallback list, so the Settings dropdown stays usable when the
     * live list is unavailable (commonly a restricted key without api.model.read).
     */
    protected function fallbackModels(string $error): array
    {
        return [
            'ok' => false,
            'models' => (array) config('openai.fallback_models', []),
            'is_fallback' => true,
            'error' => $error,
        ];
    }

    /**
     * Read a host-level setting, treating a blank stored value as unset so an
     * empty field in the admin form falls back to .env rather than breaking calls.
     */
    /**
     * The API key is stored encrypted in app_configurations. Values that are not
     * encrypted (a .env fallback, or a row written before encryption was added)
     * are returned unchanged so existing configurations keep working.
     */
    public static function decryptSecret(?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            return (string) Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value;
        }
    }

    /**
     * Encrypt a secret for storage in app_configurations.
     */
    public static function encryptSecret(string $value): string
    {
        return Crypt::encryptString($value);
    }

    protected static function setting(string $key, $default)
    {
        try {
            // Always scope by module: the same key names also exist under older,
            // now-unused modules, and an unscoped lookup returns whichever row was
            // inserted first rather than the value saved from the Settings screen.
            // ...and by owner: the host's settings, never another partner's row.
            $value = AppConfiguration::platform($key, AppConfiguration::MODULE_OPENAI);
        } catch (\Throwable $e) {
            return $default;
        }

        return filled($value) ? $value : $default;
    }

    /**
     * @param string $model
     * @param string $instructions Full text of the code-managed instruction file.
     * @param array $input Structured payload sent as the user input.
     * @param string $schemaName Name for the JSON schema (Responses API text.format.name).
     * @param array $schema Strict JSON schema the output must satisfy.
     * @param string $reasoningEffort
     * @param array $logContext Non-sensitive metadata to attach to the log line (prompt_version, etc).
     *
     * @return array{ok: bool, data: array|null, model: ?string, latency_ms: int, usage: array|null, error: ?string}
     */
    public function run(
        string $model,
        string $instructions,
        array $input,
        string $schemaName,
        array $schema,
        string $reasoningEffort,
        array $logContext = []
    ): array {
        $maxAttempts = max(1, (int) config('openai.max_attempts', 3));
        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->attempt($model, $instructions, $input, $schemaName, $schema, $reasoningEffort, array_merge($logContext, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
            ]));

            if ($result['ok'] || !$this->isRetryable($result) || $attempt === $maxAttempts) {
                break;
            }

            // Back off before retrying so a rate limit or a momentary upstream
            // problem is given time to clear rather than being hit again at once.
            $delayMs = (int) config('openai.retry_delay_ms', 2000) * $attempt;

            Log::warning('FCH Responses API retrying', array_merge($logContext, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retry_in_ms' => $delayMs,
                'failure_kind' => $result['failure_kind'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'error' => $result['error'],
            ]));

            usleep($delayMs * 1000);
        }

        return $result;
    }

    /**
     * Whether a failed attempt is worth repeating.
     *
     * Only transient conditions qualify. A 400/401/403 is a configuration or
     * payload problem that will fail identically every time, so repeating it just
     * costs the borrower more waiting. Malformed or schema-violating output is
     * treated as transient because the same prompt can produce valid output on a
     * second generation.
     */
    protected function isRetryable(array $result): bool
    {
        $kind = $result['failure_kind'] ?? null;

        if (in_array($kind, ['TRANSPORT_ERROR', 'INVALID_JSON', 'SCHEMA_VALIDATION_FAILED'], true)) {
            return true;
        }

        return $kind === 'API_ERROR'
            && in_array((int) ($result['http_status'] ?? 0), (array) config('openai.retryable_statuses', []), true);
    }

    protected function attempt(
        string $model,
        string $instructions,
        array $input,
        string $schemaName,
        array $schema,
        string $reasoningEffort,
        array $logContext = []
    ): array {
        $startTime = microtime(true);
        $store = (bool) config('openai.store', true);

        $body = [
            'model'     => $model,
            'store'     => $store,
            'instructions' => $instructions,
            'input'     => json_encode($input),
            'text'      => [
                'format'    => [
                    'type'   => 'json_schema',
                    'name'   => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        // `reasoning.effort` and non-default `text.verbosity` are only accepted by
        // reasoning-capable models (o-series, gpt-5 family). Sending either to a
        // non-reasoning model (e.g. gpt-4o-mini) is a hard 400 from the Responses
        // API, so only attach them when the configured model supports it.
        if ($this->modelSupportsReasoningEffort($model)) {
            $body['reasoning'] = ['effort' => $reasoningEffort];
            $body['text']['verbosity'] = 'low';
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post("{$this->baseUrl}/responses", $body);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logOutcome($logContext, $model, 'TRANSPORT_ERROR', $latencyMs, null);

            return [
                'ok' => false,
                'data' => null,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'usage' => null,
                'failure_kind' => 'TRANSPORT_ERROR',
                'http_status' => null,
                'error' => 'OpenAI Responses API connection error: ' . $e->getMessage(),
            ];
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        if (!$response->successful()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $response->body();
            $this->logOutcome($logContext, $model, 'API_ERROR', $latencyMs, null, $response->status(), $response->header('x-request-id') ?: null);

            return [
                'ok' => false,
                'data' => null,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'usage' => null,
                'failure_kind' => 'API_ERROR',
                'http_status' => $response->status(),
                'error' => "OpenAI Responses API error ({$response->status()}): {$errorMessage}",
            ];
        }

        $json = $response->json();
        $usage = $json['usage'] ?? null;
        $text = $this->extractOutputText($json);
        $decoded = $text !== null ? json_decode($text, true) : null;

        if (!is_array($decoded)) {
            $this->logOutcome($logContext, $model, 'INVALID_JSON', $latencyMs, $usage, $response->status(), $response->header('x-request-id') ?: null);

            return [
                'ok' => false,
                'data' => null,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'usage' => $usage,
                'failure_kind' => 'INVALID_JSON',
                'http_status' => $response->status(),
                'error' => 'OpenAI Responses API returned no parseable structured output.',
            ];
        }

        // Deterministic backend validation stays authoritative: never trust the
        // model's own "strict" flag as proof its output actually matches the
        // schema we asked for. Re-check locally before anything is persisted.
        $schemaErrors = $this->validateAgainstSchema($decoded, $schema);
        if (!empty($schemaErrors)) {
            $this->logOutcome($logContext, $model, 'SCHEMA_VALIDATION_FAILED', $latencyMs, $usage, $response->status(), $response->header('x-request-id') ?: null);

            return [
                'ok' => false,
                'data' => null,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'usage' => $usage,
                'failure_kind' => 'SCHEMA_VALIDATION_FAILED',
                'http_status' => $response->status(),
                'error' => 'Model output failed local schema validation: ' . implode('; ', $schemaErrors),
            ];
        }

        // OpenAI's id for this call, carried only in the response header. Kept so a
        // stored result can be traced back to the exact request in OpenAI's logs
        // when an evaluation is later queried or disputed.
        $requestId = $response->header('x-request-id') ?: null;

        $this->logOutcome($logContext, $model, 'SUCCESS', $latencyMs, $usage, $response->status(), $requestId);

        return [
            'ok' => true,
            'data' => $decoded,
            'model' => $json['model'] ?? $model,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'request_id' => $requestId,
            'error' => null,
        ];
    }

    /**
     * File_search citation titles, matching WlpAssistantService.php.example's
     * extractApprovedSourceTitles() — dedupes by filename since the same
     * knowledge file is commonly cited more than once in one reply.
     *
     * @return array<int, array{title: string}>
     */
    protected function extractCitations(array $response): array
    {
        $titles = [];

        foreach ((array) ($response['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                foreach ((array) ($content['annotations'] ?? []) as $annotation) {
                    if (($annotation['type'] ?? null) === 'file_citation') {
                        $title = $annotation['filename'] ?? $annotation['file_name'] ?? null;
                        if (is_string($title) && $title !== '') {
                            $titles[$title] = true;
                        }
                    }
                }
            }
        }

        return array_map(static fn (string $title) => ['title' => $title], array_keys($titles));
    }

    /**
     * Minimal, dependency-free structural validation of $data against $schema:
     * required keys present, enum membership, and recursion into object/array
     * item schemas. Not a full JSON Schema implementation — only what our own
     * FCH schemas need re-checked locally, independent of OpenAI's strict flag.
     *
     * @return string[] Human-readable error messages; empty means valid.
     */
    protected function validateAgainstSchema($data, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            if (!is_array($data)) {
                return ["{$path} must be an object"];
            }

            foreach ((array) ($schema['required'] ?? []) as $requiredKey) {
                if (!array_key_exists($requiredKey, $data)) {
                    $errors[] = "{$path}.{$requiredKey} is required but missing";
                }
            }

            foreach ((array) ($schema['properties'] ?? []) as $key => $propertySchema) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    $errors = array_merge($errors, $this->validateAgainstSchema($data[$key], $propertySchema, "{$path}.{$key}"));
                }
            }

            return $errors;
        }

        if ($type === 'array') {
            if (!is_array($data)) {
                return ["{$path} must be an array"];
            }

            if (isset($schema['items'])) {
                foreach ($data as $index => $item) {
                    $errors = array_merge($errors, $this->validateAgainstSchema($item, $schema['items'], "{$path}[{$index}]"));
                }
            }

            return $errors;
        }

        if (isset($schema['enum']) && !in_array($data, (array) $schema['enum'], true)) {
            return ["{$path} value \"" . (is_scalar($data) ? $data : json_encode($data)) . "\" is not one of the allowed enum values"];
        }

        return $errors;
    }

    protected function modelSupportsReasoningEffort(string $model): bool
    {
        $prefixes = (array) config('openai.reasoning_model_prefixes', ['o1', 'o3', 'o4', 'gpt-5']);

        foreach ($prefixes as $prefix) {
            if (stripos($model, (string) $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function extractOutputText(array $json): ?string
    {
        if (!empty($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }

        foreach ((array) ($json['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    protected function logOutcome(
        array $context,
        string $model,
        string $outcome,
        int $latencyMs,
        ?array $usage,
        ?int $httpStatus = null,
        ?string $requestId = null
    ): void {
        Log::info('FCH Responses API call', array_merge($context, [
            'model' => $model,
            'outcome' => $outcome,
            'latency_ms' => $latencyMs,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'http_status' => $httpStatus,
            // Quotable back to OpenAI support, and to the client, for one call.
            'openai_request_id' => $requestId,
        ]));
    }
}
