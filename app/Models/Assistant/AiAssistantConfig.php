<?php

namespace App\Models\Assistant;

use App\Models\Common\AppConfiguration;
use App\Services\Global\OpenAI\OpenAiResponsesService;

/**
 * Per-assistant-type settings (WLP, Borrower) — not an Eloquent model, and
 * there is no dedicated ai_assistant_configs table.
 *
 * An assistant is an Agent saved on the OpenAI platform. What is stored on
 * this side is which agent it is (encrypted, like the OpenAI API key), which
 * vector stores its knowledge lives in, and an on/off switch — saved by the
 * host from Settings > OpenAI Configuration into app_configurations.
 * The agent's wording, model and reasoning live on the platform and are used
 * from there on every message.
 *
 * The vector stores are held here rather than only on the platform because
 * every chat turn sends the file_search tool explicitly with them: an agent
 * saved without that tool (or with it pointed at the wrong store) otherwise
 * answers from the model alone, with no knowledge file read and no citation.
 */
class AiAssistantConfig
{
    public const TYPE_WLP_SUPPORT = 'wlp_support';
    public const TYPE_BORROWER_SUPPORT = 'borrower_support';

    public const TYPES = [
        self::TYPE_WLP_SUPPORT,
        self::TYPE_BORROWER_SUPPORT,
    ];

    private const FIELDS = ['agent_id', 'enabled', 'vector_store_ids'];

    /** The most vector stores one assistant may have configured. */
    public const MAX_VECTOR_STORES = 8;

    /**
     * @param array<int, array{id: string, enabled: bool}> $vector_stores
     *        The assistant's knowledge bases, in the order the host arranged
     *        them. Each carries its own switch, so a store can be parked
     *        without losing its id — which is how a knowledge base is taken out
     *        of service while it is rebuilt, then put back.
     */
    public function __construct(
        public readonly string $assistant_type,
        public ?string $agent_id = null,
        public bool $is_enabled = true,
        public array $vector_stores = [],
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
            vector_stores: self::parseStores($stored['vector_store_ids'] ?? null),
        );
    }

    /**
     * Saves the given settings; a field left out of $settings is not touched.
     *
     * @param array{agent_id?: ?string, enabled?: bool, vector_stores?: array|string|null} $settings
     */
    public static function saveSettings(string $assistantType, array $settings, ?int $partnerId = null): void
    {
        foreach (self::FIELDS as $field) {
            // The stores are stored under the long-standing "vector_store_ids"
            // key but submitted as "vector_stores", now that each one carries
            // its own switch rather than being a bare id.
            $input = $field === 'vector_store_ids' ? 'vector_stores' : $field;

            if (! array_key_exists($input, $settings)) {
                continue;
            }

            if ($field === 'enabled') {
                $value = $settings[$input] ? '1' : '0';
            } elseif ($field === 'vector_store_ids') {
                // Readable JSON: these are not secrets, and a host needs to be
                // able to check them against the OpenAI dashboard.
                $value = json_encode(self::parseStores($settings[$input]));
            } else {
                // Encrypted at rest; a blank id is stored blank (nothing to protect).
                $value = trim((string) $settings[$input]);
                $value = $value === '' ? '' : OpenAiResponsesService::encryptSecret($value);
            }

            AppConfiguration::set(self::key($assistantType, $field), $value, AppConfiguration::MODULE_OPENAI, $partnerId);
        }
    }

    private static function key(string $assistantType, string $field): string
    {
        return $assistantType . '_' . $field;
    }

    /**
     * Normalises whatever shape the stores arrive in into the canonical
     * [['id' => 'vs_...', 'enabled' => bool], ...]:
     *
     *  - the stored JSON written by saveSettings();
     *  - the repeater rows the Settings screen submits;
     *  - a bare list or a pasted string of ids, separated by commas, spaces or
     *    newlines — which is both how ids were stored before each one had its
     *    own switch, and how a host pastes several out of the OpenAI dashboard.
     *    Anything arriving without a switch is taken as enabled.
     *
     * Blank ids are dropped and a repeated id keeps only its first row, so a
     * duplicate can never be half on and half off. The order the host put them
     * in is preserved: it is the order the agent searches them.
     *
     * @return array<int, array{id: string, enabled: bool}>
     */
    public static function parseStores($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            // A stored string is JSON from saveSettings(), or — written before
            // the switches existed — a plain comma-separated list of ids.
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value);
        }

        $stores = [];

        foreach ((array) $value as $row) {
            $id = trim((string) (is_array($row) ? ($row['id'] ?? '') : $row));

            if ($id === '' || isset($stores[$id])) {
                continue;
            }

            $stores[$id] = [
                'id' => $id,
                // Only an explicit false switches a store off, so a bare id
                // keeps working exactly as it did before.
                'enabled' => ! is_array($row) || filter_var($row['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return array_values($stores);
    }

    /**
     * The ids actually searched: the switched-on stores, in the host's order.
     * A store switched off keeps its id in settings but is never sent to the
     * agent, so it cannot reach an answer.
     *
     * @return string[]
     */
    public function enabledVectorStoreIds(): array
    {
        return array_values(array_map(
            static fn (array $store) => $store['id'],
            array_filter($this->vector_stores, static fn (array $store) => $store['enabled'])
        ));
    }

    /** Whether this assistant is callable: switched on, with an agent to run. */
    public function isReady(): bool
    {
        return $this->is_enabled && filled($this->agent_id);
    }

    /**
     * This assistant's approved Take Me There destinations, from
     * config/assistant.php: route_key => ['label' => ..., 'path' => ...].
     * The only places a navigation button may ever lead.
     *
     * @return array<string, array{label: string, path: string}>
     */
    public function navigationMap(): array
    {
        return (array) config('assistant.navigation.' . $this->assistant_type, []);
    }

    /**
     * The same map as route_key => path, which is what a portal's chat widget
     * checks an action against before it renders or follows it.
     *
     * @return array<string, string>
     */
    public function navigationPaths(): array
    {
        return array_map(static fn (array $entry) => $entry['path'], $this->navigationMap());
    }

    /**
     * The file_search tool sent with every chat turn, or null when no store is
     * switched on — in which case the agent's own saved tools stand, exactly as
     * they do for an assistant with nothing configured here at all.
     *
     * @return array<string, mixed>|null
     */
    public function fileSearchTool(): ?array
    {
        $ids = $this->enabledVectorStoreIds();

        if (empty($ids)) {
            return null;
        }

        return [
            'type' => 'file_search',
            // Several stores are searched together in one tool, so a question
            // is answered from whichever of them holds the passage.
            'vector_store_ids' => $ids,
            // Retrieval width: too few chunks is the usual cause of a confident
            // but wrong answer, because the passage that held the answer never
            // reached the model.
            'max_num_results' => (int) config('openai.file_search.max_num_results', 20),
        ];
    }

    /**
     * The session-only agent override sent with every chat turn: the approved
     * knowledge base, and — unless switched off — the instruction to search it
     * before answering rather than deciding for itself whether to look.
     *
     * Empty when no vector store is configured, which leaves the saved agent's
     * own tools untouched.
     *
     * @return array<string, mixed>
     */
    public function agentOverrides(): array
    {
        $tool = $this->fileSearchTool();

        if ($tool === null) {
            return [];
        }

        $overrides = ['tools' => [$tool]];

        if (config('openai.file_search.force', true)) {
            // Forces at least one search per turn. Without it the model skips
            // the lookup whenever it believes it already knows the answer,
            // which is precisely when it is answering from training data
            // instead of from the approved files.
            $overrides['tool_choice'] = ['type' => 'file_search'];
        }

        return $overrides;
    }
}
