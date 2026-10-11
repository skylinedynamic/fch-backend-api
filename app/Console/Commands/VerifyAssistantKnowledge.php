<?php

namespace App\Console\Commands;

use App\Models\Assistant\AiAssistantConfig;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Console\Command;

/**
 * Pre-deployment check for the support assistants' knowledge retrieval.
 *
 * Answers one question with evidence rather than assurance: when a borrower or
 * a WL partner sends a message, is the approved knowledge base actually
 * searched, and does the answer come back citing it?
 *
 * It exists because the failure it checks for is silent. An agent saved on the
 * platform with "tools": [] answers every question fluently from the model's
 * own training data — no file read, no citation, and nothing in the response to
 * say so. The only way to tell the two apart is to look at whether a
 * file_search call happened and what it cited.
 *
 *   php artisan fch:verify-assistant-knowledge              # settings and store checks
 *   php artisan fch:verify-assistant-knowledge --live       # also ask each assistant a real question
 *   php artisan fch:verify-assistant-knowledge --live --ask="What documents do I need?"
 */
class VerifyAssistantKnowledge extends Command
{
    protected $signature = 'fch:verify-assistant-knowledge
        {--live : Send a real message to each assistant (costs a call) and report what it searched and cited}
        {--ask= : The question to send with --live (defaults to a document-requirements question)}';

    protected $description = 'Verify that each support assistant searches its vector stores and cites them';

    /**
     * A question whose answer must come from an approved knowledge file. A
     * model answering from memory will still produce something plausible, so
     * the citation — not the wording — is what the check reads.
     */
    private const DEFAULT_QUESTION = 'What documents are required for a standard initial working-capital funding request?';

    public function handle(OpenAiAgentsService $agents): int
    {
        $live = (bool) $this->option('live');
        $question = (string) ($this->option('ask') ?: self::DEFAULT_QUESTION);
        $ok = true;

        foreach (AiAssistantConfig::TYPES as $type) {
            $this->components->info(str_replace('_', ' ', strtoupper($type)));

            $config = AiAssistantConfig::find($type);

            if (! $this->checkSettings($config, $agents)) {
                $ok = false;
                $this->newLine();
                continue;
            }

            if ($live && ! $this->askLive($config, $agents, $question)) {
                $ok = false;
            }

            $this->newLine();
        }

        if (! $ok) {
            $this->components->error('At least one assistant cannot ground its answers in approved knowledge.');
        } elseif (! $live) {
            $this->components->info('Settings look sound. Re-run with --live to prove the knowledge base is actually searched.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What is configured on this side, and whether OpenAI agrees it exists.
     */
    private function checkSettings(AiAssistantConfig $config, OpenAiAgentsService $agents): bool
    {
        if (! $config->isReady()) {
            $this->components->warn($config->is_enabled
                ? 'No agent id saved. The chat shows the "not available yet" reply.'
                : 'Switched off in Settings. The chat is hidden for everyone this assistant serves.');

            return false;
        }

        $this->components->twoColumnDetail('Agent', $config->agent_id);

        $live = $agents->retrieveAgent($config->agent_id);

        if (! $live['ok']) {
            $this->components->error('Agent could not be read: ' . $live['error']);

            return false;
        }

        $agentStores = $this->agentVectorStoreIds((array) $live['agent']);
        $this->components->twoColumnDetail('Model', (string) ($live['agent']['model'] ?? 'unknown'));
        $this->components->twoColumnDetail(
            'Tools saved on the agent',
            empty($agentStores)
                ? '<fg=yellow>no file_search</>'
                : 'file_search → ' . implode(', ', $agentStores)
        );

        $enabled = $config->enabledVectorStoreIds();

        if (empty($config->vector_stores)) {
            $this->components->warn(
                'No vector store configured in Settings. This assistant relies entirely on the agent\'s own tools'
                . (empty($agentStores) ? ', and the agent has none — nothing will ever be searched.' : '.')
            );

            return empty($agentStores) ? false : true;
        }

        foreach ($config->vector_stores as $store) {
            $result = $agents->retrieveVectorStore($store['id']);

            if (! $result['ok']) {
                $this->components->error("{$store['id']} — {$result['error']}");

                return false;
            }

            $files = (int) ($result['store']['file_counts']['completed'] ?? 0);
            $name = (string) ($result['store']['name'] ?? 'unnamed');

            $this->components->twoColumnDetail(
                ($store['enabled'] ? '<fg=green>on </>' : '<fg=gray>off</>') . "  {$store['id']}",
                "{$name} · {$files} file" . ($files === 1 ? '' : 's')
            );

            // A store that is switched on but holds nothing retrievable is the
            // quietest failure of all: every search runs and returns nothing.
            if ($store['enabled'] && $files === 0) {
                $this->components->warn("{$store['id']} has no indexed files — searching it can only ever return nothing.");
            }
        }

        if (empty($enabled)) {
            $this->components->warn('Every store is switched off, so none of them is searched.');

            return ! empty($agentStores);
        }

        $this->components->twoColumnDetail(
            'Sent with every message',
            implode(', ', $enabled) . ($this->forced() ? ' <fg=green>(search forced)</>' : ' (model chooses when to search)')
        );

        return true;
    }

    /**
     * One real turn, read for the only thing that distinguishes a grounded
     * answer from a fluent guess: whether a file was searched and cited.
     */
    private function askLive(AiAssistantConfig $config, OpenAiAgentsService $agents, string $question): bool
    {
        $this->line('  <fg=gray>asking:</> ' . $question);

        $result = $agents->runAgent(
            $config->agent_id,
            [['role' => 'user', 'content' => $question]],
            ['assistant_type' => $config->assistant_type, 'verification' => 'cli'],
            ['assistant_type' => $config->assistant_type, 'command' => $this->getName()],
            $config->agentOverrides()
        );

        if (! $result['ok']) {
            $this->components->error('The call failed: ' . $result['error']);

            return false;
        }

        $searches = (int) ($result['file_search_calls'] ?? 0);
        $sources = (array) ($result['sources'] ?? []);

        $this->components->twoColumnDetail('Knowledge searched', $searches > 0
            ? "<fg=green>yes — {$searches} search" . ($searches === 1 ? '' : 'es') . '</>'
            : '<fg=red>no</>');

        $this->components->twoColumnDetail('Files cited', empty($sources)
            ? '<fg=red>none</>'
            : '<fg=green>' . implode(', ', array_column($sources, 'title')) . '</>');

        $this->line('  <fg=gray>answered:</> ' . str($result['text'])->squish()->limit(160));

        if (empty($sources)) {
            $this->components->error(
                'This answer cites no approved knowledge file. Either the question is not covered by the '
                . 'files in the store, or retrieval is not reaching them.'
            );

            return false;
        }

        return true;
    }

    private function forced(): bool
    {
        return (bool) config('openai.file_search.force', true);
    }

    /**
     * The vector stores attached to the saved agent's own file_search tool, so
     * the platform's configuration can be compared against ours.
     *
     * @return string[]
     */
    private function agentVectorStoreIds(array $agent): array
    {
        $ids = [];

        foreach ((array) ($agent['tools'] ?? []) as $tool) {
            if (is_array($tool) && ($tool['type'] ?? null) === 'file_search') {
                $ids = array_merge($ids, (array) ($tool['vector_store_ids'] ?? []));
            }
        }

        return array_values(array_unique(array_filter($ids, 'is_string')));
    }
}
