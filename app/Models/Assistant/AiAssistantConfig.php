<?php

namespace App\Models\Assistant;

use App\Models\Common\AppConfiguration;

/**
 * Per-assistant-type settings (WLP, Borrower) — not an Eloquent model, and
 * there is no dedicated ai_assistant_configs table.
 *
 * is_enabled/model/reasoning_effort/max_output_tokens come from
 * config/assistant.php — a plain, git-tracked config file, not a database
 * row — so turning an assistant on ships with a code deploy, never an
 * app_configurations write or an .env value.
 *
 * vector_store_id/knowledge_manifest_version stay backed by the existing
 * AppConfiguration key/value store instead: unlike the settings above, a
 * vector store id is a fact only known at runtime, discovered by actually
 * running SyncAssistantKnowledge against a live OpenAI account — it cannot
 * live in a file committed before that command ever runs.
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
     * Always returns an object — a type with no runtime settings yet just
     * comes back with vector_store_id/knowledge_manifest_version blank,
     * matching Eloquent's firstOrNew() semantics callers here rely on.
     */
    public static function find(string $assistantType): self
    {
        $configDefaults = config('assistant.' . $assistantType, []);

        $raw = AppConfiguration::get($assistantType, null, AppConfiguration::MODULE_OPENAI);
        $runtime = $raw ? (json_decode($raw, true) ?: []) : [];

        return new self(
            assistant_type: $assistantType,
            model: $configDefaults['model'] ?? null,
            reasoning_effort: $configDefaults['reasoning_effort'] ?? null,
            max_output_tokens: $configDefaults['max_output_tokens'] ?? null,
            vector_store_id: $runtime['vector_store_id'] ?? null,
            knowledge_manifest_version: $runtime['knowledge_manifest_version'] ?? null,
            is_enabled: (bool) ($configDefaults['is_enabled'] ?? false),
        );
    }

    /**
     * Persists only vector_store_id/knowledge_manifest_version — the runtime
     * facts SyncAssistantKnowledge discovers. is_enabled/model/reasoning_effort
     * come from config/assistant.php and are never written here; changing
     * them means editing that file and deploying, not calling save().
     */
    public function save(): void
    {
        AppConfiguration::set($this->assistant_type, json_encode([
            'vector_store_id' => $this->vector_store_id,
            'knowledge_manifest_version' => $this->knowledge_manifest_version,
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
     * app/Services/Assistant/Knowledge/{type}/ that get inlined into the
     * prompt instead — see SupportAssistantService::loadInlineKnowledge().
     * Deliberately under app/ (git-tracked, deploys with the code) rather
     * than storage/ (gitignored, per-environment only) — like the system
     * prompt files, this content must ship with the code, not be dropped in
     * by hand per server. The vector-store path is preferred; inline is a
     * fallback, not a replacement.
     */
    public function isReady(): bool
    {
        return $this->is_enabled
            && $this->hasSystemPrompt()
            && (filled($this->vector_store_id) || $this->hasInlineKnowledgeFiles());
    }

    public function hasInlineKnowledgeFiles(): bool
    {
        $folder = app_path('Services/Assistant/Knowledge/' . $this->assistant_type);

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
