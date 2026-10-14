<?php

namespace App\Models\PreQualification;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Common\AppConfiguration;
use App\Services\Global\OpenAI\OpenAiResponsesService;

/**
 * Which OpenAI Agent runs each step of the Pre-Qualification pipeline, and the
 * vector stores that step searches — saved by the host in Settings > OpenAI
 * Configuration, exactly as the support assistants are.
 *
 * Not an Eloquent model and no dedicated table: the values live in
 * app_configurations under the "openai" module, the agent id encrypted like
 * the API key.
 *
 * The point of this setting is that the underwriting instructions, the output
 * schema, the model and the reasoning effort all live on the OpenAI platform.
 * When a step has an agent configured, nothing about how it reasons is written
 * in this codebase: the agent is read on every run, so an edit saved on the
 * platform applies to the very next evaluation with nothing to deploy. A step
 * with no agent configured keeps running the way it always has, so an install
 * that has not been configured yet is never left without an underwriting
 * engine.
 *
 * The vector stores are held here rather than only on the platform for the
 * same reason they are for the assistants: the file_search tool is sent
 * explicitly with every run, so an agent saved without that tool (or with it
 * pointed at the wrong store) cannot silently underwrite without reading the
 * approved files.
 */
class PreQualificationAgentConfig
{
    /**
     * The one agent that underwrites a pre-qualification.
     *
     * There is exactly one. It produces the internal evaluation, and the
     * borrower's and the white-label partner's result is the borrower-safe
     * part of that same evaluation — not a second agent's rewording of it.
     * Whether the submitted information is usable is the same agent's
     * data_status, not a separate validator's verdict.
     *
     * One agent, one set of instructions, one schema, one place to edit.
     */
    public const TYPE_AGENT = 'prequalification_agent';

    public const TYPES = [
        self::TYPE_AGENT,
    ];

    private const FIELDS = ['agent_id', 'enabled', 'vector_store_ids'];

    /** The most vector stores one step may have configured. */
    public const MAX_VECTOR_STORES = 8;

    /**
     * @param array<int, array{id: string, enabled: bool}> $vector_stores
     *        The knowledge bases this step searches, in the order the host
     *        arranged them. Each carries its own switch, so a store can be
     *        parked while it is rebuilt without losing its id.
     */
    public function __construct(
        public readonly string $step,
        public ?string $agent_id = null,
        public bool $is_enabled = true,
        public array $vector_stores = [],
    ) {
    }

    public static function find(string $step): self
    {
        $stored = [];

        try {
            foreach (self::FIELDS as $field) {
                $value = AppConfiguration::platform(self::key($step, $field), AppConfiguration::MODULE_OPENAI);

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

        $agentId = $stored['agent_id'] ?? null;
        $enabled = $stored['enabled'] ?? null;

        return new self(
            step: $step,
            agent_id: $agentId,
            // Explicitly switched off stays off, explicitly on stays on. With
            // no switch ever saved, the agent decides: a step nobody has
            // configured is off.
            //
            // The assistants default to on instead, because an assistant that
            // is on without an agent simply does not appear. A step that is on
            // without an agent is a different thing: the Settings screen marks
            // its Agent ID required, so defaulting these three to on would make
            // the whole OpenAI screen — API key included — unsaveable on a
            // fresh install, before a host had any way to configure them.
            is_enabled: $enabled === null ? filled($agentId) : $enabled !== '0',
            vector_stores: AiAssistantConfig::parseStores($stored['vector_store_ids'] ?? null),
        );
    }

    /**
     * Saves the given settings; a field left out of $settings is not touched.
     *
     * @param array{agent_id?: ?string, enabled?: bool, vector_stores?: array|string|null} $settings
     */
    public static function saveSettings(string $step, array $settings, ?int $partnerId = null): void
    {
        foreach (self::FIELDS as $field) {
            // Stored under "vector_store_ids" and submitted as "vector_stores",
            // matching the assistants so one Settings screen speaks one shape.
            $input = $field === 'vector_store_ids' ? 'vector_stores' : $field;

            if (! array_key_exists($input, $settings)) {
                continue;
            }

            if ($field === 'enabled') {
                $value = $settings[$input] ? '1' : '0';
            } elseif ($field === 'vector_store_ids') {
                // Readable JSON: these are not secrets, and a host needs to be
                // able to check them against the OpenAI dashboard.
                $value = json_encode(AiAssistantConfig::parseStores($settings[$input]));
            } else {
                // Encrypted at rest; a blank id is stored blank (nothing to protect).
                $value = trim((string) $settings[$input]);
                $value = $value === '' ? '' : OpenAiResponsesService::encryptSecret($value);
            }

            AppConfiguration::set(self::key($step, $field), $value, AppConfiguration::MODULE_OPENAI, $partnerId);
        }
    }

    private static function key(string $step, string $field): string
    {
        return $step . '_' . $field;
    }

    /**
     * The ids actually searched: the switched-on stores, in the host's order.
     * A store switched off keeps its id in settings but is never sent, so it
     * cannot reach an evaluation.
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

    /**
     * Whether this step runs on its agent: switched on, with an agent to run.
     * False means the step falls back to the instructions and schema that ship
     * with the codebase.
     */
    public function isReady(): bool
    {
        return $this->is_enabled && filled($this->agent_id);
    }

    /**
     * The file_search tool sent with every run, or null when no store is
     * switched on — in which case the agent's own saved tools stand.
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
            // Several stores are searched together in one tool, so a rule is
            // found in whichever of them holds it.
            'vector_store_ids' => $ids,
            'max_num_results' => (int) config('openai.file_search.max_num_results', 20),
        ];
    }

    /**
     * The session-only overrides sent with every run: the approved knowledge
     * base, and — unless switched off — the instruction to search it before
     * answering rather than deciding for itself whether to look.
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
            // Forces at least one search per evaluation. Left to the model, a
            // policy it "already knows" is applied from training data rather
            // than from the files the host approved.
            $overrides['tool_choice'] = ['type' => 'file_search'];
        }

        return $overrides;
    }
}
