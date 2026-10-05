<?php

namespace App\Models\Assistant;

use App\Models\Common\AppConfiguration;
use App\Services\Global\OpenAI\OpenAiResponsesService;

/**
 * Per-assistant-type settings (WLP, Borrower) — not an Eloquent model, and
 * there is no dedicated ai_assistant_configs table.
 *
 * An assistant is an Agent saved on the OpenAI platform. The only things
 * stored on this side are which agent it is (encrypted, like the OpenAI API
 * key) and an on/off switch — saved by the host from Settings > OpenAI
 * Configuration into app_configurations.
 * Everything the agent says and knows (instructions, model, tools,
 * knowledge) lives on the platform and is used from there on every message.
 */
class AiAssistantConfig
{
    public const TYPE_WLP_SUPPORT = 'wlp_support';
    public const TYPE_BORROWER_SUPPORT = 'borrower_support';

    public const TYPES = [
        self::TYPE_WLP_SUPPORT,
        self::TYPE_BORROWER_SUPPORT,
    ];

    private const FIELDS = ['agent_id', 'enabled'];

    public function __construct(
        public readonly string $assistant_type,
        public ?string $agent_id = null,
        public bool $is_enabled = true,
    ) {
    }

    public static function find(string $assistantType): self
    {
        $stored = [];

        try {
            foreach (self::FIELDS as $field) {
                $value = AppConfiguration::get(self::key($assistantType, $field), null, AppConfiguration::MODULE_OPENAI);

                // The agent id is stored encrypted, like the OpenAI API key. A
                // row saved before that was the case is read as it is.
                if ($field === 'agent_id') {
                    $value = OpenAiResponsesService::decryptSecret($value);
                }

                $stored[$field] = filled($value) ? trim((string) $value) : null;
            }
        } catch (\Throwable $e) {
            // Table not migrated yet: nothing configured.
        }

        return new self(
            assistant_type: $assistantType,
            agent_id: $stored['agent_id'] ?? null,
            // On unless it was explicitly switched off.
            is_enabled: ($stored['enabled'] ?? '1') !== '0',
        );
    }

    /**
     * Saves the given settings; a field left out of $settings is not touched.
     *
     * @param array{agent_id?: ?string, enabled?: bool} $settings
     */
    public static function saveSettings(string $assistantType, array $settings, ?int $partnerId = null): void
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $settings)) {
                continue;
            }

            if ($field === 'enabled') {
                $value = $settings[$field] ? '1' : '0';
            } else {
                // Encrypted at rest; a blank id is stored blank (nothing to protect).
                $value = trim((string) $settings[$field]);
                $value = $value === '' ? '' : OpenAiResponsesService::encryptSecret($value);
            }

            AppConfiguration::set(self::key($assistantType, $field), $value, AppConfiguration::MODULE_OPENAI, $partnerId);
        }
    }

    private static function key(string $assistantType, string $field): string
    {
        return $assistantType . '_' . $field;
    }

    /** Whether this assistant is callable: switched on, with an agent to run. */
    public function isReady(): bool
    {
        return $this->is_enabled && filled($this->agent_id);
    }
}
