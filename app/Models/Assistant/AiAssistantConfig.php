<?php

namespace App\Models\Assistant;

use App\Models\Common\AppConfiguration;

/**
 * Per-assistant-type settings (WLP, Borrower) — not an Eloquent model, and
 * there is no dedicated ai_assistant_configs table. Backed by the existing
 * AppConfiguration key/value store (AppConfiguration::MODULE_OPENAI already
 * holds openai_api_key), one JSON-encoded row per assistant_type, the same
 * way any other admin-editable settings live in this codebase.
 */
class AiAssistantConfig
{
    public const TYPE_WLP_SUPPORT = 'wlp_support';
    public const TYPE_BORROWER_SUPPORT = 'borrower_support';

    public const TYPES = [
        self::TYPE_WLP_SUPPORT,
        self::TYPE_BORROWER_SUPPORT,
    ];

    public function __construct(
        public readonly string $assistant_type,
        public ?string $model = null,
        public ?string $reasoning_effort = null,
        public ?int $max_output_tokens = null,
        public ?string $vector_store_id = null,
        public ?string $knowledge_manifest_version = null,
        public bool $is_enabled = false,
    ) {
    }

    /**
     * Always returns an object — a type with no saved settings yet just
     * comes back all-blank/disabled, matching Eloquent's firstOrNew()
     * semantics the admin screen and callers here already rely on.
     */
    public static function find(string $assistantType): self
    {
        $raw = AppConfiguration::get($assistantType, null, AppConfiguration::MODULE_OPENAI);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];

        return new self(
            assistant_type: $assistantType,
            model: $data['model'] ?? null,
            reasoning_effort: $data['reasoning_effort'] ?? null,
            max_output_tokens: $data['max_output_tokens'] ?? null,
            vector_store_id: $data['vector_store_id'] ?? null,
            knowledge_manifest_version: $data['knowledge_manifest_version'] ?? null,
            is_enabled: (bool) ($data['is_enabled'] ?? false),
        );
    }

    public function save(): void
    {
        AppConfiguration::set($this->assistant_type, json_encode([
            'model' => $this->model,
            'reasoning_effort' => $this->reasoning_effort,
            'max_output_tokens' => $this->max_output_tokens,
            'vector_store_id' => $this->vector_store_id,
            'knowledge_manifest_version' => $this->knowledge_manifest_version,
            'is_enabled' => $this->is_enabled,
        ]), AppConfiguration::MODULE_OPENAI);
    }

    /**
     * Whether this assistant is actually callable: enabled, with a system
     * prompt and a knowledge base attached. Missing either means the real
     * handoff content (still pending) hasn't been loaded yet.
     *
     * "Knowledge attached" is satisfied either by a real vector_store_id
     * (the intended File Search architecture) or, when the configured
     * OpenAI key lacks file/vector-store write scopes, by local files under
     * storage/app/assistant-knowledge/{type}/ that get inlined into the
     * prompt instead — see SupportAssistantService::loadInlineKnowledge().
     * The vector-store path is preferred; inline is a fallback, not a
     * replacement.
     */
    public function isReady(): bool
    {
        return $this->is_enabled
            && $this->hasSystemPrompt()
            && (filled($this->vector_store_id) || $this->hasInlineKnowledgeFiles());
    }

    public function hasInlineKnowledgeFiles(): bool
    {
        $folder = storage_path('app/assistant-knowledge/' . $this->assistant_type);

        return is_dir($folder) && count(glob($folder . '/*') ?: []) > 0;
    }

    /**
     * The system prompt is a versioned code artifact, not a DB column — like
     * PreQualificationClientAueService's *_Responses_Instructions.txt, it
     * ships beside the service that uses it and only changes via a code
     * deploy, never through the admin Assistant Config screen.
     */
    public function systemPromptPath(): string
    {
        return app_path("Services/Assistant/{$this->assistant_type}_system_prompt.txt");
    }

    public function systemPrompt(): string
    {
        return file_exists($this->systemPromptPath()) ? (string) file_get_contents($this->systemPromptPath()) : '';
    }

    public function hasSystemPrompt(): bool
    {
        return trim($this->systemPrompt()) !== '';
    }
}
